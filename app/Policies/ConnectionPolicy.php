<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;

class ConnectionPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageConnections);
    }

    public function view(User $user, Connection $connection): bool
    {
        return $user->hasTeamPermission($connection->team, TeamPermission::ManageConnections);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageConnections);
    }

    public function delete(User $user, Connection $connection): bool
    {
        return $user->hasTeamPermission($connection->team, TeamPermission::ManageConnections);
    }

    public function sync(User $user, Connection $connection): bool
    {
        return $user->hasTeamPermission($connection->team, TeamPermission::ManageConnections);
    }
}
