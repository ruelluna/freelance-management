<?php

namespace App\Actions\Users;

use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateClientUser
{
    public function handle(Team $team, Client $client, string $name, string $email, string $password): User
    {
        if ($client->team_id !== $team->id || ! $client->isActive()) {
            throw ValidationException::withMessages([
                'clientId' => __('Choose a client from the list.'),
            ]);
        }

        $user = DB::transaction(function () use ($team, $client, $name, $email, $password): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();

            $team->members()->attach($user, ['role' => TeamRole::Client->value]);
            $client->users()->attach($user);
            $user->switchTeam($team);

            return $user;
        });

        Log::info('Client user created', [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'client_id' => $client->id,
        ]);

        return $user;
    }
}
