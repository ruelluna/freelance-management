<?php

namespace App\Actions\Issues;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamResourceAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ResolveTaskAssignees
{
    /**
     * @param  array<int, int|string>  $userIds
     * @return Collection<int, int>
     */
    public function handle(Team $team, array $userIds, ?User $actor = null): Collection
    {
        $requestedIds = collect($userIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();

        $allowedIds = $actor === null
            ? $team->staff()->pluck('users.id')
            : TeamResourceAccess::for($actor, $team)->assignableUsers()->pluck('id');

        $matchedIds = $requestedIds
            ->intersect($allowedIds)
            ->values();

        if ($matchedIds->count() !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'assigneeIds' => $actor?->isTeamClient($team)
                    ? __('Choose the account owner or someone assigned to your projects.')
                    : __('Only you and your employees can be assigned to a task.'),
            ]);
        }

        return $matchedIds;
    }
}
