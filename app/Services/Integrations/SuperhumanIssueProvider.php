<?php

namespace App\Services\Integrations;

use App\Contracts\IssueProvider;
use App\Data\Integrations\IssueUpdate;
use App\Data\Integrations\RemoteComment;
use App\Data\Integrations\RemoteIssue;
use App\Data\Integrations\RemoteSource;
use App\Enums\IssueStatus;
use App\Enums\Provider;
use App\Exceptions\ProviderNotImplementedException;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Superhuman Docs adapter (formerly Coda).
 *
 * A ConnectedSource is one board on a page. Rows are imported when a People
 * column lists the app user or the token account. The board's nextSyncToken
 * is stored and sent back as syncToken. Comments are not pushed.
 */
class SuperhumanIssueProvider implements IssueProvider
{
    /**
     * @var array<int, string>
     */
    public const DEFAULT_CLOSED_STATUSES = ['Done', 'Complete', 'Completed', 'Closed'];

    /**
     * @return Collection<int, RemoteSource>
     */
    public function listSources(Connection $connection): Collection
    {
        return $this->listDocs($connection);
    }

    public function ownerEmail(string $token): string
    {
        $payload = $this->client($token)
            ->get('/whoami')
            ->throw()
            ->json();

        $email = is_array($payload) ? ($payload['loginId'] ?? null) : null;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Superhuman Docs did not return an account email.');
        }

        return strtolower($email);
    }

    /**
     * @return Collection<int, RemoteSource>
     */
    public function listDocs(Connection $connection): Collection
    {
        return $this->items($connection->token, '/docs')
            ->map(fn (array $doc): ?RemoteSource => $this->namedSource($doc))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, RemoteSource>
     */
    public function listTables(Connection $connection, string $docId): Collection
    {
        return $this->items($connection->token, '/docs/'.$docId.'/tables')
            ->map(fn (array $table): ?RemoteSource => $this->namedSource($table))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, RemoteSource>
     */
    public function listPages(Connection $connection, string $docId): Collection
    {
        return $this->items($connection->token, '/docs/'.$docId.'/pages')
            ->map(function (array $page): ?RemoteSource {
                $source = $this->namedSource($page);

                if ($source === null) {
                    return null;
                }

                $parent = $page['parent']['name'] ?? null;

                if (! is_string($parent) || $parent === '') {
                    return $source;
                }

                return new RemoteSource(
                    externalId: $source->externalId,
                    name: $parent.' / '.$source->name,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * Tables and views on a page. An embedded page has none of its own, so the
     * same page name is resolved in the doc that owns the table.
     *
     * @return Collection<int, array{id: string, name: string, doc_id: string}>
     */
    public function listBoards(Connection $connection, string $docId, string $pageId): Collection
    {
        $tables = $this->tablesOnPage($connection, $docId, $pageId);

        if ($tables->isNotEmpty()) {
            return $tables;
        }

        $page = $this->pagePayload($connection, $docId, $pageId);
        $pageName = mb_strtolower(trim((string) ($page['name'] ?? '')));

        if ($page === null || ($page['contentType'] ?? '') !== 'embed' || $pageName === '') {
            return collect();
        }

        foreach ($this->listDocs($connection) as $doc) {
            if ($doc->externalId === $docId) {
                continue;
            }

            try {
                $pages = $this->items($connection->token, '/docs/'.$doc->externalId.'/pages');
            } catch (Throwable) {
                continue;
            }

            foreach ($pages as $candidate) {
                $candidateId = $candidate['id'] ?? null;
                $candidateName = mb_strtolower(trim((string) ($candidate['name'] ?? '')));

                if (! is_string($candidateId) || $candidateId === '' || $candidateName !== $pageName) {
                    continue;
                }

                $found = $this->tablesOnPage($connection, $doc->externalId, $candidateId);

                if ($found->isNotEmpty()) {
                    return $found;
                }
            }
        }

        return collect();
    }

    /**
     * @return Collection<int, array{id: string, name: string, doc_id: string}>
     */
    protected function tablesOnPage(Connection $connection, string $docId, string $pageId): Collection
    {
        return $this->items($connection->token, '/docs/'.$docId.'/tables')
            ->filter(function (array $table) use ($pageId): bool {
                $parentId = $table['parent']['id'] ?? null;

                return is_string($parentId) && $parentId === $pageId;
            })
            ->map(function (array $table) use ($docId): ?array {
                $source = $this->namedSource($table);

                if ($source === null) {
                    return null;
                }

                return [
                    'id' => $source->externalId,
                    'name' => $source->name,
                    'doc_id' => $docId,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pagePayload(Connection $connection, string $docId, string $pageId): ?array
    {
        try {
            $payload = $this->client($connection->token)
                ->get('/docs/'.$docId.'/pages/'.rawurlencode($pageId))
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return Collection<int, array{id: string, name: string, format: string}>
     */
    public function columnDetails(Connection $connection, string $docId, string $tableId): Collection
    {
        return $this->items($connection->token, '/docs/'.$docId.'/tables/'.$tableId.'/columns')
            ->map(function (array $column): ?array {
                $id = $column['id'] ?? null;
                $name = $column['name'] ?? null;

                if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                    return null;
                }

                $format = $column['format']['type'] ?? '';

                return [
                    'id' => $id,
                    'name' => $name,
                    'format' => is_string($format) ? strtolower($format) : '',
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, RemoteIssue>
     */
    public function listIssues(ConnectedSource $source): Collection
    {
        $source->loadMissing('connection.user');
        $docId = $this->docId($source);
        $boardId = $this->boardId($source);
        $matchEmails = $this->matchEmails($source);
        $issues = collect();
        $dropped = [];
        $columns = $this->columnDetails($source->connection, $docId, $boardId);
        $personColumnIds = $this->assigneeColumnIds($columns);
        $query = [
            'valueFormat' => 'rich',
            'limit' => 100,
        ];
        $existingToken = $source->settings['sync_token'] ?? null;

        if (is_string($existingToken) && $existingToken !== '') {
            $query['syncToken'] = $existingToken;
        }

        $page = $this->paginate($source->connection->token, '/docs/'.$docId.'/tables/'.$boardId.'/rows', $query);
        $settings = $source->settings ?? [];

        if (is_string($page['nextSyncToken']) && $page['nextSyncToken'] !== '') {
            $settings['sync_token'] = $page['nextSyncToken'];
            $source->update(['settings' => $settings]);
        }

        foreach ($page['items'] as $row) {
            $rowId = $row['id'] ?? null;

            if (! is_string($rowId) || $rowId === '') {
                continue;
            }

            $externalId = $boardId.'/'.$rowId;
            $remote = $this->mapDocRow(
                $source,
                $row,
                $externalId,
                $personColumnIds,
                $this->columnIdByName($columns, ['status', 'state']),
                $this->columnIdByName($columns, ['notes', 'description', 'details']),
                $matchEmails,
                filterAssignee: true,
            );

            if ($remote === null) {
                $dropped[] = $externalId;

                continue;
            }

            $issues->push($remote);
        }

        if ($dropped !== []) {
            Issue::query()
                ->where('connected_source_id', $source->id)
                ->whereIn('external_id', $dropped)
                ->delete();
        }

        return $issues->values();
    }

    public function getIssue(Issue $issue): RemoteIssue
    {
        $issue->loadMissing('connectedSource.connection.user', 'connection');
        $source = $issue->connectedSource;

        if ($source === null) {
            throw new RuntimeException('Superhuman issue is missing its doc.');
        }

        $parts = explode('/', $issue->external_id, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('Superhuman issue is missing its table row.');
        }

        [$tableId, $rowId] = $parts;
        $docId = $this->docId($source);
        $columns = $this->columnDetails($issue->connection, $docId, $tableId);
        $personColumnIds = $this->assigneeColumnIds($columns);

        $payload = $this->client($issue->connection->token)
            ->get('/docs/'.$docId.'/tables/'.$tableId.'/rows/'.rawurlencode($rowId), [
                'valueFormat' => 'rich',
            ])
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Unexpected Superhuman Docs row payload.');
        }

        $remote = $this->mapDocRow(
            $source,
            $payload,
            $issue->external_id,
            $personColumnIds,
            $this->columnIdByName($columns, ['status', 'state']),
            $this->columnIdByName($columns, ['notes', 'description', 'details']),
            $this->matchEmails($source),
            filterAssignee: false,
        );

        if ($remote === null) {
            throw new RuntimeException('Unexpected Superhuman Docs row payload.');
        }

        return $remote;
    }

    /**
     * @return Collection<int, RemoteComment>
     */
    public function listComments(Issue $issue): Collection
    {
        return collect();
    }

    public function createComment(Issue $issue, string $body): RemoteComment
    {
        throw new ProviderNotImplementedException(Provider::Superhuman);
    }

    public function createIssue(
        ConnectedSource $source,
        string $title,
        ?string $body,
        array $labelNames = [],
        array $assigneeLogins = [],
    ): RemoteIssue {
        throw new ProviderNotImplementedException(Provider::Superhuman);
    }

    public function updateIssue(Issue $issue, IssueUpdate $update): void
    {
        throw new ProviderNotImplementedException(Provider::Superhuman);
    }

    protected function docId(ConnectedSource $source): string
    {
        $docId = explode('/', $source->external_id, 2)[0];

        if ($docId === '') {
            throw new RuntimeException('Superhuman source is missing a doc.');
        }

        return $docId;
    }

    protected function boardId(ConnectedSource $source): string
    {
        $boardId = explode('/', $source->external_id, 2)[1] ?? '';

        if ($boardId === '') {
            throw new RuntimeException('Superhuman source is missing a board.');
        }

        return $boardId;
    }

    /**
     * @return array<int, string>
     */
    protected function matchEmails(ConnectedSource $source): array
    {
        $source->loadMissing('connection.user');
        $stored = $source->settings['match_emails'] ?? [];
        $emails = [
            strtolower((string) ($source->connection->settings['owner_email'] ?? '')),
            strtolower((string) ($source->connection->user?->email ?? '')),
        ];

        if (is_array($stored)) {
            foreach ($stored as $email) {
                if (is_string($email)) {
                    $emails[] = strtolower($email);
                }
            }
        }

        return array_values(array_unique(array_filter(
            $emails,
            fn (string $email): bool => $email !== '',
        )));
    }

    /**
     * @param  Collection<int, array{id: string, name: string, format: string}>  $columns
     * @param  array<int, string>  $names
     */
    protected function columnIdByName(Collection $columns, array $names): ?string
    {
        $match = $columns->first(function (array $column) use ($names): bool {
            return in_array(strtolower(trim($column['name'])), $names, true);
        });

        if (! is_array($match)) {
            return null;
        }

        return $match['id'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $personColumnIds
     * @param  array<int, string>  $matchEmails
     */
    protected function mapDocRow(
        ConnectedSource $source,
        array $row,
        string $externalId,
        array $personColumnIds,
        ?string $statusColumnId,
        ?string $notesColumnId,
        array $matchEmails,
        bool $filterAssignee,
    ): ?RemoteIssue {
        $values = is_array($row['values'] ?? null) ? $row['values'] : [];
        $statusText = $statusColumnId === null ? null : $this->cellText($values[$statusColumnId] ?? null);

        if ($filterAssignee && (! $this->rowMatches($values, $personColumnIds, $matchEmails) || $this->isClosedStatus($source, $statusText))) {
            return null;
        }

        $title = $this->cellText($row['name'] ?? null) ?? 'Untitled';
        $body = $notesColumnId === null ? null : $this->cellText($values[$notesColumnId] ?? null);
        $browserLink = $row['browserLink'] ?? null;

        return new RemoteIssue(
            externalId: $externalId,
            number: null,
            title: $title,
            body: $body,
            status: $this->statusFromText($source, $statusText),
            externalUrl: is_string($browserLink) && $browserLink !== '' ? $browserLink : null,
            externalUpdatedAt: isset($row['updatedAt']) ? Date::parse($row['updatedAt']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $personColumnIds
     * @param  array<int, string>  $matchEmails
     */
    protected function rowMatches(array $values, array $personColumnIds, array $matchEmails): bool
    {
        if ($matchEmails === []) {
            return false;
        }

        $cells = $personColumnIds === []
            ? array_values(array_filter($values, fn (mixed $value): bool => $this->isPersonValue($value)))
            : array_map(fn (string $columnId): mixed => $values[$columnId] ?? null, $personColumnIds);

        foreach ($cells as $cell) {
            foreach ($this->cellEmails($cell) as $email) {
                if (in_array($email, $matchEmails, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isPersonValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                if ($this->isPersonValue($item)) {
                    return true;
                }
            }

            return false;
        }

        $type = $value['@type'] ?? $value['additionalType'] ?? null;

        return is_string($type) && strtolower($type) === 'person';
    }

    /**
     * @param  Collection<int, array{id: string, name: string, format: string}>  $columns
     * @return array<int, string>
     */
    protected function assigneeColumnIds(Collection $columns): array
    {
        $assignedInto = $this->columnIdByName($columns, ['assigned into']);

        if ($assignedInto !== null) {
            return [$assignedInto];
        }

        return $columns
            ->where('format', 'person')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    protected function isClosedStatus(ConnectedSource $source, ?string $statusText): bool
    {
        return $this->statusFromText($source, $statusText) === IssueStatus::Closed;
    }

    protected function statusFromText(ConnectedSource $source, ?string $statusText): IssueStatus
    {
        if ($statusText === null || $statusText === '') {
            return IssueStatus::Open;
        }

        $closed = $source->settings['closed_statuses'] ?? self::DEFAULT_CLOSED_STATUSES;

        if (! is_array($closed)) {
            return IssueStatus::Open;
        }

        $normalized = strtolower(trim($statusText));

        foreach ($closed as $status) {
            if (is_string($status) && strtolower(trim($status)) === $normalized) {
                return IssueStatus::Closed;
            }
        }

        return IssueStatus::Open;
    }

    protected function cellText(mixed $value): ?string
    {
        if (is_string($value)) {
            $text = $this->unwrapPlainText($value);

            return $text === '' ? null : $text;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            $parts = [];

            foreach ($value as $item) {
                $text = $this->cellText($item);

                if ($text !== null) {
                    $parts[] = $text;
                }
            }

            $joined = implode(', ', $parts);

            return $joined === '' ? null : $joined;
        }

        foreach (['name', 'email', 'url'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
                return $value[$key];
            }
        }

        return null;
    }

    protected function unwrapPlainText(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '```') && str_ends_with($trimmed, '```') && strlen($trimmed) >= 6) {
            return trim(substr($trimmed, 3, -3));
        }

        return $trimmed;
    }

    /**
     * @return array<int, string>
     */
    protected function cellEmails(mixed $value): array
    {
        $emails = [];
        $this->collectEmails($value, $emails);

        return array_values(array_unique($emails));
    }

    /**
     * @param  array<int, string>  $emails
     */
    protected function collectEmails(mixed $value, array &$emails): void
    {
        if (is_string($value)) {
            $text = $this->unwrapPlainText($value);

            if (filter_var($text, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = strtolower($text);
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $this->collectEmails($item, $emails);
            }

            return;
        }

        if (isset($value['email']) && is_string($value['email']) && $value['email'] !== '') {
            $emails[] = strtolower($value['email']);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function namedSource(array $item): ?RemoteSource
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
            return null;
        }

        return new RemoteSource(externalId: $id, name: $name);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function items(string $token, string $path): Collection
    {
        return $this->paginate($token, $path, ['limit' => 100])['items'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: Collection<int, array<string, mixed>>, nextSyncToken: ?string}
     */
    protected function paginate(string $token, string $path, array $query): array
    {
        $items = collect();
        $pageToken = null;
        $nextSyncToken = null;

        for ($page = 0; $page < 50; $page++) {
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->client($token)
                ->get($path, $query)
                ->throw()
                ->json();

            if (! is_array($payload)) {
                break;
            }

            foreach ($payload['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items->push($item);
                }
            }

            if (is_string($payload['nextSyncToken'] ?? null) && $payload['nextSyncToken'] !== '') {
                $nextSyncToken = $payload['nextSyncToken'];
            }

            $nextPage = $payload['nextPageToken'] ?? null;

            if (! is_string($nextPage) || $nextPage === '' || $nextPage === $pageToken) {
                break;
            }

            $pageToken = $nextPage;
        }

        return [
            'items' => $items,
            'nextSyncToken' => $nextSyncToken,
        ];
    }

    protected function client(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.superhuman.api_url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(3)
            ->retry([100, 500], function (mixed $exception, mixed ...$extra): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429);
            });
    }
}
