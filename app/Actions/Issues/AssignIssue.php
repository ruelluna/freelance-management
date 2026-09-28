<?php

namespace App\Actions\Issues;

use App\Data\Integrations\IssueUpdate;
use App\Enums\Provider;
use App\Models\Issue;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AssignIssue
{
    public function __construct(private IssueProviderFactory $providers) {}

    /**
     * @param  array<int, int>  $userIds
     */
    public function handle(Issue $issue, array $userIds): Issue
    {
        $teamMemberIds = $issue->team->members()->whereIn('users.id', $userIds)->pluck('users.id');

        $issue->assignees()->sync($teamMemberIds);

        if ($issue->connection_id === null) {
            return $issue->fresh(['assignees']) ?? $issue;
        }

        $logins = UserIdentity::query()
            ->where('provider', Provider::Github)
            ->whereIn('user_id', $teamMemberIds)
            ->pluck('external_id')
            ->all();

        try {
            $this->providers->make($issue->connection)->updateIssue($issue, new IssueUpdate(
                assigneeLogins: $logins,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Failed to push assignees to source', [
                'issue_id' => $issue->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $issue->fresh(['assignees']) ?? $issue;
    }

    /**
     * @return Collection<int, User>
     */
    public function assignableMembers(Issue $issue): Collection
    {
        return $issue->team->members()->orderBy('name')->get();
    }
}
