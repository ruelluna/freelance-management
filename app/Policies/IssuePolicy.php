<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Issue;
use App\Models\Team;
use App\Models\User;

class IssuePolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ViewIssues);
    }

    public function view(User $user, Issue $issue): bool
    {
        return $this->viewAny($user, $issue->team);
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
        return $user->belongsToTeam($issue->team)
            && $user->hasTeamPermission($issue->team, TeamPermission::CommentOnIssues);
    }

    public function assign(User $user, Issue $issue): bool
    {
        return $user->belongsToTeam($issue->team)
            && $user->hasTeamPermission($issue->team, TeamPermission::AssignIssues);
    }
}
