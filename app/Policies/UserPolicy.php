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

        return $this->managesStaff($user, $team)
            && $this->staffRole($member, $team) !== null;
    }

    public function create(User $user, ?Team $team = null): bool
    {
        return $this->managesStaff($user, $team);
    }

    public function update(User $user, User $member): bool
    {
        $team = $user->currentTeam;
        $role = $team === null ? null : $this->staffRole($member, $team);

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

    private function staffRole(User $member, Team $team): ?TeamRole
    {
        $role = $member->teamRole($team);

        if ($role === null || $role->isClient()) {
            return null;
        }

        return $role;
    }
}
