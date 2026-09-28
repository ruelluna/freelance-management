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
 * Todoist adapter (planned).
 *
 * Mapping: a ConnectedSource is a Todoist project (or an "assigned to me" filter).
 * Tasks become Issues, Todoist comments become IssueComments (two-way),
 * and Todoist labels become team Labels. Complete/reopen maps to closed/open.
 * Auth is a personal API token against https://api.todoist.com/api/v1.
 */
class TodoistIssueProvider implements IssueProvider
{
    public function listSources(Connection $connection): Collection
    {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }

    public function listIssues(ConnectedSource $source): Collection
    {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }

    public function getIssue(Issue $issue): RemoteIssue
    {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }

    public function listComments(Issue $issue): Collection
    {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }

    public function createComment(Issue $issue, string $body): RemoteComment
    {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }

    public function createIssue(
        ConnectedSource $source,
        string $title,
        ?string $body,
        array $labelNames = [],
        array $assigneeLogins = [],
    ): RemoteIssue {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }

    public function updateIssue(Issue $issue, IssueUpdate $update): void
    {
        throw new ProviderNotImplementedException(Provider::Todoist);
    }
}
