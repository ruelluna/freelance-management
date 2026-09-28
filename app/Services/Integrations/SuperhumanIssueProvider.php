<?php

namespace App\Services\Integrations;

use App\Contracts\IssueProvider;
use App\Data\Integrations\IssueUpdate;
use App\Data\Integrations\RemoteComment;
use App\Data\Integrations\RemoteIssue;
use App\Enums\Provider;
use App\Exceptions\ProviderNotImplementedException;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use Illuminate\Support\Collection;

/**
 * Superhuman Docs adapter (planned; formerly Coda).
 *
 * A ConnectedSource is a doc + table. Column mapping in source settings is required:
 * title, status, assignee (Person), body/notes, optional comments text column.
 * Pull rows assigned to the connection owner (Person email / UserIdentity).
 * Comments are appended to a mapped Notes/Comments column — the row API has no issue threads.
 * Poll only. Status strings such as Done/Complete map to closed in source settings.
 * API: https://docs.superhuman.com/apis/v1
 */
class SuperhumanIssueProvider implements IssueProvider
{
    public function listSources(Connection $connection): Collection
    {
        throw new ProviderNotImplementedException(Provider::Superhuman);
    }

    public function listIssues(ConnectedSource $source): Collection
    {
        throw new ProviderNotImplementedException(Provider::Superhuman);
    }

    public function getIssue(Issue $issue): RemoteIssue
    {
        throw new ProviderNotImplementedException(Provider::Superhuman);
    }

    public function listComments(Issue $issue): Collection
    {
        throw new ProviderNotImplementedException(Provider::Superhuman);
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
}
