<?php

namespace App\Contracts;

use App\Data\Integrations\IssueUpdate;
use App\Data\Integrations\RemoteComment;
use App\Data\Integrations\RemoteIssue;
use App\Data\Integrations\RemoteSource;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use Illuminate\Support\Collection;

interface IssueProvider
{
    /**
     * @return Collection<int, RemoteSource>
     */
    public function listSources(Connection $connection): Collection;

    /**
     * @return Collection<int, RemoteIssue>
     */
    public function listIssues(ConnectedSource $source): Collection;

    public function getIssue(Issue $issue): RemoteIssue;

    /**
     * @return Collection<int, RemoteComment>
     */
    public function listComments(Issue $issue): Collection;

    public function createComment(Issue $issue, string $body): RemoteComment;

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
    ): RemoteIssue;

    public function updateIssue(Issue $issue, IssueUpdate $update): void;
}
