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
use App\Support\PublicAppUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GithubIssueProvider implements IssueProvider
{
    /**
     * @return Collection<int, RemoteSource>
     */
    public function listSources(Connection $connection): Collection
    {
        $sources = collect();

        for ($page = 1; $page <= 100; $page++) {
            $repos = $this->client($connection->token)
                ->get('/user/repos', [
                    'per_page' => 100,
                    'page' => $page,
                    'sort' => 'full_name',
                    'visibility' => 'all',
                    'affiliation' => 'owner,collaborator,organization_member',
                ])
                ->throw()
                ->json();

            if (! is_array($repos) || $repos === []) {
                break;
            }

            $sources = $sources->merge($this->mapRepoItems($repos));

            if (count($repos) < 100) {
                break;
            }
        }

        return $sources->unique(fn (RemoteSource $source): string => $source->externalId)->values();
    }

    /**
     * Search repositories by name, including private repos the token can access.
     *
     * @return Collection<int, RemoteSource>
     */
    public function searchSources(Connection $connection, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $response = $this->client($connection->token)
            ->get('/search/repositories', [
                'q' => $query.' in:name fork:true',
                'sort' => 'updated',
                'per_page' => 100,
            ])
            ->throw()
            ->json();

        return $this->mapRepoItems($response['items'] ?? [])
            ->unique(fn (RemoteSource $source): string => $source->externalId)
            ->values();
    }

    /**
     * @return Collection<int, RemoteIssue>
     */
    public function listIssues(ConnectedSource $source): Collection
    {
        $issues = collect();
        $query = [
            'state' => 'all',
            'per_page' => 100,
        ];

        if ($source->last_synced_at) {
            $query['since'] = $source->last_synced_at->toIso8601String();
        }

        for ($page = 1; $page <= 20; $page++) {
            $payload = $this->client($source->connection->token)
                ->get($this->repoPath($source, 'issues'), [...$query, 'page' => $page])
                ->throw()
                ->json();

            if (! is_array($payload) || $payload === []) {
                break;
            }

            foreach ($payload as $item) {
                if (isset($item['pull_request'])) {
                    continue;
                }

                $issues->push($this->mapIssue($item));
            }

            if (count($payload) < 100) {
                break;
            }
        }

        return $issues->values();
    }

    public function getIssue(Issue $issue): RemoteIssue
    {
        $payload = $this->client($issue->connection->token)
            ->get($this->repoPath($issue->connectedSource, 'issues/'.$issue->number))
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new \RuntimeException('Unexpected GitHub issue payload.');
        }

        return $this->mapIssue($payload);
    }

    /**
     * @return Collection<int, RemoteComment>
     */
    public function listComments(Issue $issue): Collection
    {
        $comments = collect();

        for ($page = 1; $page <= 20; $page++) {
            $payload = $this->client($issue->connection->token)
                ->get($this->repoPath($issue->connectedSource, 'issues/'.$issue->number.'/comments'), [
                    'per_page' => 100,
                    'page' => $page,
                ])
                ->throw()
                ->json();

            if (! is_array($payload) || $payload === []) {
                break;
            }

            foreach ($payload as $item) {
                $comments->push($this->mapComment($item));
            }

            if (count($payload) < 100) {
                break;
            }
        }

        return $comments->values();
    }

    public function createComment(Issue $issue, string $body): RemoteComment
    {
        $payload = $this->client($issue->connection->token)
            ->post($this->repoPath($issue->connectedSource, 'issues/'.$issue->number.'/comments'), [
                'body' => $body,
            ])
            ->throw()
            ->json();

        return $this->mapComment($payload);
    }

    public function fetchIssueBodyHtml(ConnectedSource $source, int $number): ?string
    {
        $payload = $this->htmlClient($source->connection->token)
            ->get($this->repoPath($source, 'issues/'.$number))
            ->throw()
            ->json();

        if (! is_array($payload)) {
            return null;
        }

        $body = $payload['body'] ?? null;

        return is_string($body) && trim($body) !== '' ? $body : null;
    }

    public function fetchCommentBodyHtml(ConnectedSource $source, int $commentId): ?string
    {
        $payload = $this->htmlClient($source->connection->token)
            ->get($this->repoPath($source, 'issues/comments/'.$commentId))
            ->throw()
            ->json();

        if (! is_array($payload)) {
            return null;
        }

        $body = $payload['body'] ?? null;

        return is_string($body) && trim($body) !== '' ? $body : null;
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
            'title' => $title,
            'body' => $body,
            'labels' => $labelNames === [] ? null : $labelNames,
            'assignees' => $assigneeLogins === [] ? null : $assigneeLogins,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->client($source->connection->token)
            ->post($this->repoPath($source, 'issues'), $payload)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new \RuntimeException('Unexpected GitHub issue payload.');
        }

        return $this->mapIssue($response);
    }

    public function updateIssue(Issue $issue, IssueUpdate $update): void
    {
        $payload = array_filter([
            'state' => $update->status,
            'title' => $update->title,
            'body' => $update->body,
            'labels' => $update->labelNames,
            'assignees' => $update->assigneeLogins,
        ], fn (mixed $value): bool => $value !== null);

        if ($payload === []) {
            return;
        }

        $this->client($issue->connection->token)
            ->patch($this->repoPath($issue->connectedSource, 'issues/'.$issue->number), $payload)
            ->throw();
    }

    public function registerWebhook(Connection $connection, ConnectedSource $source): void
    {
        if (! PublicAppUrl::canReceiveWebhooks() || blank($connection->webhook_secret)) {
            return;
        }

        try {
            $response = $this->client($connection->token)
                ->post($this->repoPath($source, 'hooks'), [
                    'name' => 'web',
                    'active' => true,
                    'events' => ['issues', 'issue_comment'],
                    'config' => [
                        'url' => route('webhooks.github', $connection),
                        'content_type' => 'json',
                        'secret' => $connection->webhook_secret,
                        'insecure_ssl' => '0',
                    ],
                ])
                ->throw()
                ->json();

            $settings = $source->settings ?? [];
            $settings['webhook_id'] = $response['id'] ?? null;
            $source->update(['settings' => $settings]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to register GitHub webhook', [
                'source_id' => $source->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function deleteWebhook(ConnectedSource $source): void
    {
        $webhookId = $source->settings['webhook_id'] ?? null;

        if (! $webhookId) {
            return;
        }

        try {
            $this->client($source->connection->token)
                ->delete($this->repoPath($source, 'hooks/'.$webhookId))
                ->throw();
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete GitHub webhook', [
                'source_id' => $source->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapIssue(array $payload): RemoteIssue
    {
        return new RemoteIssue(
            externalId: (string) $payload['id'],
            number: isset($payload['number']) ? (int) $payload['number'] : null,
            title: $payload['title'] ?? '',
            body: $payload['body'] ?? null,
            status: ($payload['state'] ?? 'open') === 'closed' ? IssueStatus::Closed : IssueStatus::Open,
            externalUrl: $payload['html_url'] ?? null,
            externalUpdatedAt: isset($payload['updated_at']) ? Date::parse($payload['updated_at']) : null,
            labels: collect($payload['labels'] ?? [])
                ->map(fn (array $label): array => [
                    'name' => $label['name'],
                    'color' => $label['color'] ?? 'ededed',
                ])
                ->values()
                ->all(),
            assigneeLogins: collect($payload['assignees'] ?? [])
                ->pluck('login')
                ->filter()
                ->values()
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapComment(array $payload): RemoteComment
    {
        return new RemoteComment(
            externalId: (string) $payload['id'],
            body: $payload['body'] ?? '',
            authorName: $payload['user']['login'] ?? 'unknown',
            createdAt: isset($payload['created_at']) ? Date::parse($payload['created_at']) : null,
        );
    }

    protected function client(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('services.github.api_url'))
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeader('X-GitHub-Api-Version', (string) config('services.github.api_version'))
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 500, 1000]);
    }

    protected function htmlClient(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('services.github.api_url'))
            ->withToken($token)
            ->accept('application/vnd.github.html+json')
            ->withHeader('X-GitHub-Api-Version', (string) config('services.github.api_version'))
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 500, 1000]);
    }

    protected function repoPath(ConnectedSource $source, string $suffix = ''): string
    {
        [$owner, $repo] = explode('/', $source->external_id, 2);

        $path = '/repos/'.$owner.'/'.$repo;

        return $suffix === '' ? $path : $path.'/'.ltrim($suffix, '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, RemoteSource>
     */
    protected function mapRepoItems(array $items): Collection
    {
        return collect($items)->map(fn (array $repo): RemoteSource => new RemoteSource(
            externalId: $repo['full_name'],
            name: $repo['full_name'],
            meta: [
                'private' => $repo['private'] ?? false,
                'html_url' => $repo['html_url'] ?? null,
            ],
        ));
    }
}
