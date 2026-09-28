<?php

namespace App\Actions\Projects;

use App\Enums\TeamRole;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssignProjectMembers
{
    /**
     * @param  array<int, int|string>  $userIds
     */
    public function handle(Project $project, array $userIds): Project
    {
        $memberIds = $project->team->members()
            ->wherePivot('role', TeamRole::Member->value)
            ->pluck('users.id')
            ->all();

        $invalidIds = array_diff($userIds, $memberIds);

        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'memberIds' => [__('Only team members with the Member role can be assigned to a project.')],
            ]);
        }

        $project->members()->sync($userIds);

        Log::info('Project members assigned', [
            'project_id' => $project->id,
            'team_id' => $project->team_id,
            'user_ids' => $userIds,
        ]);

        return $project->fresh(['members']) ?? $project;
    }
}
