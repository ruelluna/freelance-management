<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ViewIssues);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->viewAny($user, $project->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageProjects);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team)
            && $user->hasTeamPermission($project->team, TeamPermission::ManageProjects);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
