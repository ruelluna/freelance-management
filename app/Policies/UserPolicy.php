<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user, ?Team $team = null): bool
    {
        return $this->managesStaff($user, $team);
    }

    public function view(User $user, User $member): bool
    {
        $team = $user->currentTeam;

        return $team !== null
            && $this->managesStaff($user, $team)
            && $member->teamRole($team) !== null;
    }

    public function create(User $user, ?Team $team = null): bool
    {
        return $this->managesStaff($user, $team);
    }

    public function update(User $user, User $member): bool
    {
        $team = $user->currentTeam;
        $role = $team === null ? null : $member->teamRole($team);

        return $this->managesStaff($user, $team)
            && $role !== null
            && $role !== TeamRole::Owner;
    }

    public function delete(User $user, User $member): bool
    {
        return $this->update($user, $member)
            && $user->id !== $member->id;
    }

    private function managesStaff(User $user, ?Team $team): bool
    {
        return $team !== null
            && $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageUsers);
    }
}
