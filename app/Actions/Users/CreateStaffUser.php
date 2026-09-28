<?php

namespace App\Actions\Users;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Queries\StaffRoleQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateStaffUser
{
    public function handle(Team $team, string $name, string $email, string $password, TeamRole $role): User
    {
        if (! in_array($role->value, (new StaffRoleQuery)->names(), true)) {
            throw ValidationException::withMessages([
                'role' => __('Choose a role from the list.'),
            ]);
        }

        $user = DB::transaction(function () use ($team, $name, $email, $password, $role): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();

            $team->members()->attach($user, ['role' => $role->value]);
            $user->switchTeam($team);

            return $user;
        });

        Log::info('Staff user created', [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'role' => $role->value,
        ]);

        return $user;
    }
}
