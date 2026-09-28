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
     * Accounts the token can browse: the authenticated user, then their organizations.
     *
     * @return Collection<int, array{login: string, personal: bool}>
     */
    public function listAccounts(Connection $connection): Collection
    {
        $user = $this->client($connection->token)->retry(1)->get('/user')->throw()->json();
        $login = is_array($user) ? (string) ($user['login'] ?? '') : '';

        $accounts = collect();

        if ($login !== '') {
            $accounts->push([
                'login' => $login,
                'personal' => true,
            ]);
        }

        try {
            for ($page = 1; $page <= 10; $page++) {
                $orgs = $this->client($connection->token)->retry(1)->get('/user/orgs', [
                    'per_page' => 100,
                    'page' => $page,
                ])->throw()->json();

                if (! is_array($orgs) || $orgs === []) {
                    break;
                }

                foreach ($orgs as $org) {
                    $orgLogin = is_array($org) ? (string) ($org['login'] ?? '') : '';

                    if ($orgLogin === '') {
                        continue;
                    }

                    $accounts->push([
                        'login' => $orgLogin,
                        'personal' => false,
                    ]);
                }

                if (count($orgs) < 100) {
                    break;
                }
            }
        } catch (\Throwable) {
            // A fine-grained token can read the user without permission to list organizations.
        }

        return $accounts
            ->unique('login')
            ->sortBy([
                ['personal', 'desc'],
                ['login', 'asc'],
            ])
            ->values();
    }

    /**
     * Repositories for one account. Personal accounts include owned and collaborator
     * repos. Organizations are listed from that org, including private repos.
     *
     * @return Collection<int, RemoteSource>
     */
    public function listAccountRepositories(Connection $connection, string $login, bool $personal): Collection
    {
        $sources = collect();

        for ($page = 1; $page <= 100; $page++) {
            $response = $personal
                ? $this->client($connection->token)->get('/user/repos', [
                    'per_page' => 100,
                    'page' => $page,
                    'sort' => 'full_name',
                    'visibility' => 'all',
                    'affiliation' => 'owner,collaborator',
                ])
                : $this->client($connection->token)->get('/orgs/'.rawurlencode($login).'/repos', [
                    'per_page' => 100,
                    'page' => $page,
                    'sort' => 'full_name',
                    'type' => 'all',
                ]);

            $repos = $response->throw()->json();

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
     * An `owner/repo` query is resolved with the repository endpoint. GitHub's
     * search API omits private repositories unless the query is scoped to a
     * user or organization, and `in:name` does not match an owner/name string.
     *
     * @return Collection<int, RemoteSource>
     */
    public function searchSources(Connection $connection, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        if (preg_match('/^(?<owner>[A-Za-z0-9_.-]+)\/(?<repo>[A-Za-z0-9_.-]+)$/', $query, $matches) === 1) {
            return $this->findRepository($connection->token, $matches['owner'], $matches['repo']);
        }

        $response = $this->client($connection->token)
            ->get('/search/repositories', [
                'q' => $this->repositorySearchQuery($connection->token, $query),
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

        return $this->renderedHtmlBody($payload);
    }

    public function fetchCommentBodyHtml(ConnectedSource $source, int $commentId): ?string
    {
        $payload = $this->htmlClient($source->connection->token)
            ->get($this->repoPath($source, 'issues/comments/'.$commentId))
            ->throw()
            ->json();

        return $this->renderedHtmlBody($payload);
    }

    protected function renderedHtmlBody(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['body_html', 'body'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
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
     * @return Collection<int, RemoteSource>
     */
    protected function findRepository(string $token, string $owner, string $repo): Collection
    {
        $response = $this->client($token)->retry(1)->get('/repos/'.$owner.'/'.$repo);

        if ($response->notFound() || $response->forbidden()) {
            return collect();
        }

        $payload = $response->throw()->json();

        if (! is_array($payload) || ! isset($payload['full_name'])) {
            return collect();
        }

        return $this->mapRepoItems([$payload]);
    }

    protected function repositorySearchQuery(string $token, string $query): string
    {
        $scope = $this->repositorySearchScope($token);
        $q = $query.' in:name fork:true';

        return $scope === '' ? $q : $q.' '.$scope;
    }

    /**
     * GitHub's repository search returns private repos only when the query is
     * limited to the authenticated user or one of their organizations.
     */
    protected function repositorySearchScope(string $token): string
    {
        try {
            $user = $this->client($token)->retry(1)->get('/user')->throw()->json();
        } catch (\Throwable) {
            return '';
        }

        $qualifiers = [];
        $login = is_array($user) ? ($user['login'] ?? null) : null;

        if (is_string($login) && $login !== '') {
            $qualifiers[] = 'user:'.$login;
        }

        try {
            $orgs = $this->client($token)->retry(1)->get('/user/orgs', ['per_page' => 100])->throw()->json();

            foreach (is_array($orgs) ? $orgs : [] as $org) {
                $orgLogin = is_array($org) ? ($org['login'] ?? null) : null;

                if (is_string($orgLogin) && $orgLogin !== '') {
                    $qualifiers[] = 'org:'.$orgLogin;
                }
            }
        } catch (\Throwable) {
            // Org membership is optional. The token can still search the owner's private repos.
        }

        return match (count($qualifiers)) {
            0 => '',
            1 => $qualifiers[0],
            default => '('.implode(' OR ', $qualifiers).')',
        };
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
