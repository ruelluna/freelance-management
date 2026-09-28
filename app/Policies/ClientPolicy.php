<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Client;
use App\Models\Team;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageClients);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->belongsToTeam($client->team)
            && $user->hasTeamPermission($client->team, TeamPermission::ManageClients);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageClients);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->belongsToTeam($client->team)
            && $user->hasTeamPermission($client->team, TeamPermission::ManageClients);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    public function inviteUser(User $user, Client $client): bool
    {
        return $this->update($user, $client) && $client->isActive();
    }
}
