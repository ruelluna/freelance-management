<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamResourceAccess;

class ProjectPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ViewIssues);
    }

    public function view(User $user, Project $project): bool
    {
        if (! $this->viewAny($user, $project->team)) {
            return false;
        }

        return TeamResourceAccess::for($user, $project->team)->canAccessProject($project);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageProjects);
    }

    public function createOnProject(User $user, Project $project): bool
    {
        if (! $user->belongsToTeam($project->team)
            || ! $user->hasTeamPermission($project->team, TeamPermission::CreateIssues)) {
            return false;
        }

        return TeamResourceAccess::for($user, $project->team)->canAccessProject($project);
    }

    public function update(User $user, Project $project): bool
    {
        if (! $user->belongsToTeam($project->team)) {
            return false;
        }

        if (! $user->hasTeamPermission($project->team, TeamPermission::ManageProjects)) {
            return false;
        }

        return TeamResourceAccess::for($user, $project->team)->canAccessProject($project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
