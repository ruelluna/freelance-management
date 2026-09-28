<?php

namespace App\Services\Integrations;

use App\Contracts\IssueProvider;
use App\Data\Integrations\IssueUpdate;
use App\Data\Integrations\RemoteComment;
use App\Data\Integrations\RemoteIssue;
use App\Data\Integrations\RemoteSource;
use App\Enums\IssueStatus;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

class TodoistIssueProvider implements IssueProvider
{
    /**
     * @return Collection<int, RemoteSource>
     */
    public function listSources(Connection $connection): Collection
    {
        return collect([
            new RemoteSource(
                externalId: 'all',
                name: 'All tasks',
            ),
        ]);
    }

    public function verifyToken(string $token): void
    {
        $this->client($token)
            ->get('/tasks', ['limit' => 1])
            ->throw();
    }

    /**
     * @return Collection<int, RemoteIssue>
     */
    public function listIssues(ConnectedSource $source): Collection
    {
        $from = $this->syncFrom();
        $initial = ! isset($source->settings['initial_import_at']);
        $since = $source->last_synced_at;

        $filter = $initial
            ? 'created after: '.$from->copy()->subDay()->toDateString().' & created before: '.now()->addDay()->toDateString()
            : 'created after: '.($since ?? $from)->copy()->subDay()->toDateString();

        return $this->paginate($source->connection->token, '/tasks/filter', [
            'query' => $filter,
            'lang' => 'en',
            'limit' => 200,
        ], 'results')
            ->filter(function (array $task) use ($initial, $from, $since): bool {
                if (! isset($task['added_at'])) {
                    return $initial;
                }

                $addedAt = Date::parse($task['added_at']);

                if ($initial) {
                    return $addedAt->gte($from) && $addedAt->lte(now()->endOfDay());
                }

                return $since === null || $addedAt->gt($since);
            })
            ->map(fn (array $task): RemoteIssue => $this->mapTask($task))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listCompletedTasks(ConnectedSource $source): Collection
    {
        $from = $this->syncFrom();
        $initial = ! isset($source->settings['initial_import_at']);
        $since = $initial ? $from : ($source->last_synced_at ?? $from)->copy()->subDay();

        if ($since->lt($from)) {
            $since = $from;
        }

        $tasks = collect();
        $until = now();

        while ($since->lt($until)) {
            $chunkEnd = $since->copy()->addDays(80);

            if ($chunkEnd->gt($until)) {
                $chunkEnd = $until;
            }

            $tasks = $tasks->merge($this->paginate($source->connection->token, '/tasks/completed/by_completion_date', [
                'since' => $since->toIso8601String(),
                'until' => $chunkEnd->toIso8601String(),
                'limit' => 200,
            ], 'items'));

            $since = $chunkEnd;
        }

        return $tasks->unique('id')->values();
    }

    /**
     * @return array<int, string>
     */
    public function listExternalIdsCreatedBeforeCutoff(ConnectedSource $source): array
    {
        $tasks = $this->paginate($source->connection->token, '/tasks/filter', [
            'query' => 'created before: '.$this->syncFrom()->toDateString(),
            'lang' => 'en',
            'limit' => 200,
        ], 'results');

        return $tasks
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function getIssue(Issue $issue): RemoteIssue
    {
        $payload = $this->client($issue->connection->token)
            ->get('/tasks/'.$issue->external_id)
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new \RuntimeException('Unexpected Todoist task payload.');
        }

        return $this->mapTask($payload);
    }

    /**
     * @return Collection<int, RemoteComment>
     */
    public function listComments(Issue $issue): Collection
    {
        $comments = collect();
        $cursor = null;

        for ($page = 0; $page < 20; $page++) {
            $query = [
                'task_id' => $issue->external_id,
                'limit' => 200,
            ];

            if (is_string($cursor) && $cursor !== '') {
                $query['cursor'] = $cursor;
            }

            $payload = $this->client($issue->connection->token)
                ->get('/comments', $query)
                ->throw()
                ->json();

            if (! is_array($payload)) {
                break;
            }

            foreach ($payload['results'] ?? [] as $comment) {
                if (! is_array($comment) || ($comment['is_deleted'] ?? false) === true) {
                    continue;
                }

                $comments->push($this->mapComment($comment));
            }

            $cursor = $payload['next_cursor'] ?? null;

            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $comments->values();
    }

    public function createComment(Issue $issue, string $body): RemoteComment
    {
        $payload = $this->client($issue->connection->token)
            ->post('/comments', [
                'task_id' => $issue->external_id,
                'content' => $body,
            ])
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new \RuntimeException('Unexpected Todoist comment payload.');
        }

        return $this->mapComment($payload);
    }

    /**
     * @param  array<int, string>  $labelNames
     * @param  array<int, string>  $assigneeLogins
     */
    public function createIssue(
        ConnectedSource $source,
        string $title,
        ?string $body,
        array $labelNames = [],
        array $assigneeLogins = [],
    ): RemoteIssue {
        $payload = array_filter([
            'content' => $title,
            'description' => $body,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->client($source->connection->token)
            ->post('/tasks', $payload)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new \RuntimeException('Unexpected Todoist task payload.');
        }

        return $this->mapTask($response);
    }

    public function updateIssue(Issue $issue, IssueUpdate $update): void
    {
        $payload = array_filter([
            'content' => $update->title,
            'description' => $update->body,
        ], fn (mixed $value): bool => $value !== null);

        if ($payload !== []) {
            $this->client($issue->connection->token)
                ->post('/tasks/'.$issue->external_id, $payload)
                ->throw();
        }

        if ($update->status === IssueStatus::Closed->value) {
            $this->client($issue->connection->token)
                ->post('/tasks/'.$issue->external_id.'/close')
                ->throw();
        }

        if ($update->status === IssueStatus::Open->value) {
            $this->client($issue->connection->token)
                ->post('/tasks/'.$issue->external_id.'/reopen')
                ->throw();
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function mapTask(array $task): RemoteIssue
    {
        $id = (string) $task['id'];
        $description = $task['description'] ?? null;

        return new RemoteIssue(
            externalId: $id,
            number: null,
            title: (string) ($task['content'] ?? ''),
            body: is_string($description) && $description !== '' ? $description : null,
            status: ($task['checked'] ?? false) ? IssueStatus::Closed : IssueStatus::Open,
            externalUrl: 'https://app.todoist.com/app/task/'.$id,
            externalUpdatedAt: isset($task['updated_at']) ? Date::parse($task['updated_at']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    protected function mapComment(array $comment): RemoteComment
    {
        return new RemoteComment(
            externalId: (string) $comment['id'],
            body: (string) ($comment['content'] ?? ''),
            authorName: 'Todoist',
            createdAt: isset($comment['posted_at']) ? Date::parse($comment['posted_at']) : null,
        );
    }

    protected function syncFrom(): CarbonInterface
    {
        return Date::parse((string) config('services.todoist.sync_from'))->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, array<string, mixed>>
     */
    protected function paginate(string $token, string $path, array $query, string $listKey): Collection
    {
        $items = collect();
        $cursor = null;

        for ($page = 0; $page < 50; $page++) {
            if (is_string($cursor) && $cursor !== '') {
                $query['cursor'] = $cursor;
            }

            $payload = $this->client($token)
                ->get($path, $query)
                ->throw()
                ->json();

            if (! is_array($payload)) {
                break;
            }

            $rows = $payload[$listKey] ?? [];

            if ($rows === [] && isset($payload['results']) && is_array($payload['results'])) {
                $rows = $payload['results'];
            }

            foreach ($rows as $item) {
                if (is_array($item)) {
                    $items->push($item);
                }
            }

            $cursor = $payload['next_cursor'] ?? null;

            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $items;
    }

    protected function client(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('services.todoist.api_url'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 500], function (mixed $exception, mixed ...$extra): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException && $exception->response->serverError();
            });
    }
}
