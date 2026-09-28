<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Label;
use App\Models\Team;
use App\Models\User;

class LabelPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageLabels);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageLabels);
    }

    public function update(User $user, Label $label): bool
    {
        return $user->hasTeamPermission($label->team, TeamPermission::ManageLabels);
    }

    public function delete(User $user, Label $label): bool
    {
        return $user->hasTeamPermission($label->team, TeamPermission::ManageLabels);
    }
}
