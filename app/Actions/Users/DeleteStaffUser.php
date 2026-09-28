<?php

namespace App\Actions\Users;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteStaffUser
{
    public function handle(Team $team, User $user): void
    {
        $role = $user->teamRole($team);

        if ($role === null || $role === TeamRole::Owner) {
            throw ValidationException::withMessages([
                'user' => __('This user cannot be deleted.'),
            ]);
        }

        $userId = $user->id;

        DB::transaction(function () use ($team, $user, $role): void {
            $issueIds = $user->assignedIssues()
                ->where('issues.team_id', $team->id)
                ->pluck('issues.id');

            $user->assignedIssues()->detach($issueIds);

            $projectIds = $user->assignedProjects()
                ->where('projects.team_id', $team->id)
                ->pluck('projects.id');

            $user->assignedProjects()->detach($projectIds);

            if ($role->isClient()) {
                $clientIds = $user->clients()
                    ->where('clients.team_id', $team->id)
                    ->pluck('clients.id');

                $user->clients()->detach($clientIds);
            }

            $team->memberships()
                ->where('user_id', $user->id)
                ->delete();

            $user->unsetRelation('teams');

            if ($user->teams()->doesntExist()) {
                $user->delete();

                return;
            }

            if ($user->current_team_id === $team->id) {
                $fallback = $user->fallbackTeam();

                if ($fallback !== null) {
                    $user->switchTeam($fallback);
                }
            }
        });

        Log::info('Staff user deleted', [
            'user_id' => $userId,
            'team_id' => $team->id,
        ]);
    }
}
