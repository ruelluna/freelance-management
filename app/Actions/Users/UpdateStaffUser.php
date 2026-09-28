<?php

namespace App\Actions\Users;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Queries\StaffRoleQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateStaffUser
{
    public function handle(
        Team $team,
        User $user,
        string $name,
        string $email,
        TeamRole $role,
        ?string $password,
    ): User {
        $currentRole = $user->teamRole($team);

        if ($currentRole === null || $currentRole === TeamRole::Owner) {
            throw ValidationException::withMessages([
                'role' => __('This user cannot be updated here.'),
            ]);
        }

        $allowed = $currentRole->isClient()
            ? [TeamRole::Client->value]
            : (new StaffRoleQuery)->names();

        if (! in_array($role->value, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => __('Choose a role from the list.'),
            ]);
        }

        DB::transaction(function () use ($team, $user, $name, $email, $role, $password): void {
            $user->forceFill([
                'name' => $name,
                'email' => $email,
            ]);

            if (filled($password)) {
                $user->password = $password;
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = now();
            }

            $user->save();

            $team->memberships()
                ->where('user_id', $user->id)
                ->firstOrFail()
                ->update(['role' => $role]);
        });

        Log::info('Staff user updated', [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'role' => $role->value,
        ]);

        return $user->fresh() ?? $user;
    }
}
