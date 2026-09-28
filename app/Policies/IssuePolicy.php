<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Issue;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamResourceAccess;

class IssuePolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ViewIssues);
    }

    public function view(User $user, Issue $issue): bool
    {
        if (! $this->viewAny($user, $issue->team)) {
            return false;
        }

        return TeamResourceAccess::for($user, $issue->team)->canAccessIssue($issue);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::CreateIssues);
    }

    public function update(User $user, Issue $issue): bool
    {
        if (! $user->belongsToTeam($issue->team)) {
            return false;
        }

        if (! TeamResourceAccess::for($user, $issue->team)->canAccessIssue($issue)) {
            return false;
        }

        if ($user->hasTeamPermission($issue->team, TeamPermission::UpdateIssues)) {
            return true;
        }

        if (! $user->hasTeamPermission($issue->team, TeamPermission::ViewIssues)) {
            return false;
        }

        return $issue->created_by === $user->id || $issue->isAssignedTo($user);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $this->update($user, $issue);
    }

    public function comment(User $user, Issue $issue): bool
    {
        if (! $user->belongsToTeam($issue->team)) {
            return false;
        }

        if (! $user->hasTeamPermission($issue->team, TeamPermission::CommentOnIssues)) {
            return false;
        }

        return TeamResourceAccess::for($user, $issue->team)->canAccessIssue($issue);
    }

    public function assign(User $user, Issue $issue): bool
    {
        if (! $user->belongsToTeam($issue->team)) {
            return false;
        }

        if (! $user->hasTeamPermission($issue->team, TeamPermission::AssignIssues)) {
            return false;
        }

        return TeamResourceAccess::for($user, $issue->team)->canAccessIssue($issue);
    }
}
