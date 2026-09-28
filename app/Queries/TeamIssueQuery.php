<?php

namespace App\Queries;

use App\Enums\Provider;
use App\Models\Issue;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamResourceAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TeamIssueQuery
{
    /**
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     clientId?: string,
     *     projectId?: string,
     *     labelId?: string,
     *     sourceId?: string,
     *     assigneeId?: string,
     * }  $filters
     */
    public function __construct(
        private User $user,
        private Team $team,
        private array $filters = [],
    ) {}

    public static function for(User $user, Team $team): self
    {
        return new self($user, $team);
    }

    /**
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     clientId?: string,
     *     projectId?: string,
     *     labelId?: string,
     *     sourceId?: string,
     *     assigneeId?: string,
     * }  $filters
     */
    public function applyFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @return LengthAwarePaginator<int, Issue>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate($perPage);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    /**
     * @return Builder<Issue>
     */
    private function baseQuery(): Builder
    {
        $access = TeamResourceAccess::for($this->user, $this->team);

        $query = $access->scopeIssues(
            Issue::query()->whereBelongsTo($this->team)
        );

        $status = $this->filters['status'] ?? 'open';
        $search = $this->filters['search'] ?? '';
        $clientId = $this->filters['clientId'] ?? '';
        $projectId = $this->filters['projectId'] ?? '';
        $labelId = $this->filters['labelId'] ?? '';
        $sourceId = $this->filters['sourceId'] ?? '';
        $assigneeId = $this->filters['assigneeId'] ?? '';

        $relations = ['assignees', 'project.client', 'client', 'creator'];

        if (! $this->user->isTeamClient($this->team)) {
            array_unshift($relations, 'labels');
            $relations[] = 'connection';
        }

        return $query
            ->with($relations)
            ->when($status !== 'all', fn (Builder $builder) => $builder->where('status', $status))
            ->when($search !== '', fn (Builder $builder) => $builder->where('title', 'like', '%'.$search.'%'))
            ->when($labelId !== '', fn (Builder $builder) => $builder->whereHas('labels', fn (Builder $labels) => $labels->where('labels.id', $labelId)))
            ->when($sourceId === 'manual', fn (Builder $builder) => $builder->whereNull('connection_id'))
            ->when($sourceId === 'todoist', fn (Builder $builder) => $builder->whereHas(
                'connection',
                fn (Builder $connections) => $connections->where('provider', Provider::Todoist),
            ))
            ->when($sourceId === 'coda', fn (Builder $builder) => $builder->whereHas(
                'connection',
                fn (Builder $connections) => $connections->where('provider', Provider::Superhuman),
            ))
            ->when($sourceId === 'github', fn (Builder $builder) => $builder->whereHas(
                'connection',
                fn (Builder $connections) => $connections->where('provider', Provider::Github),
            ))
            ->when($assigneeId !== '', fn (Builder $builder) => $builder->whereHas('assignees', fn (Builder $assignees) => $assignees->where('users.id', $assigneeId)))
            ->when($projectId !== '', fn (Builder $builder) => $builder->where('project_id', $projectId))
            ->when($access->hasUnrestrictedAccess() && $clientId === 'internal', fn (Builder $builder) => $builder->where(function (Builder $inner) {
                $inner->whereNull('client_id')
                    ->where(function (Builder $projects) {
                        $projects->whereNull('project_id')
                            ->orWhereHas('project', fn (Builder $query) => $query->whereNull('client_id'));
                    });
            }))
            ->when($access->hasUnrestrictedAccess() && filled($clientId) && $clientId !== 'internal', fn (Builder $builder) => $builder->where(function (Builder $inner) use ($clientId) {
                $inner->where('client_id', $clientId)
                    ->orWhere(function (Builder $fromProject) use ($clientId) {
                        $fromProject->whereNull('client_id')
                            ->whereHas('project', fn (Builder $projects) => $projects->where('client_id', $clientId));
                    });
            }))
            ->latest('updated_at')
            ->latest('id');
    }
}
