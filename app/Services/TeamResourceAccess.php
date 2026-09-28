<?php

namespace App\Services;

use App\Enums\ClientStatus;
use App\Enums\Provider;
use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class TeamResourceAccess
{
    /** @var array<string>|null */
    private ?array $clientIds = null;

    /** @var array<string>|null */
    private ?array $assignedProjectIds = null;

    private bool $resolvedClientIds = false;

    private bool $resolvedProjectIds = false;

    public function __construct(
        private User $user,
        private Team $team,
    ) {}

    public static function for(User $user, Team $team): self
    {
        return new self($user, $team);
    }

    public function hasUnrestrictedAccess(): bool
    {
        $role = $this->user->teamRole($this->team);

        return in_array($role, [TeamRole::Owner, TeamRole::Admin], true);
    }

    public function isClientUser(): bool
    {
        return $this->user->teamRole($this->team)?->isClient() ?? false;
    }

    public function isRestrictedMember(): bool
    {
        return $this->user->teamRole($this->team) === TeamRole::Member;
    }

    public function isScopedUser(): bool
    {
        return $this->isClientUser() || $this->isRestrictedMember();
    }

    /**
     * People this user may assign to a task.
     *
     * Staff can assign the owner and employees. Every client can assign the
     * team owner, plus employees attached to that client's projects.
     *
     * @return Collection<int, User>
     */
    public function assignableUsers(): Collection
    {
        $staff = $this->team->staff()->orderBy('users.name');

        if (! $this->isClientUser()) {
            return $staff->get();
        }

        $projectIds = $this->scopeProjects($this->team->projects())->pluck('id');

        $ownerIds = $this->team->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->pluck('users.id');

        $projectMemberIds = $projectIds->isEmpty()
            ? collect()
            : $this->team->staff()
                ->whereHas('assignedProjects', function (Builder $projects) use ($projectIds): void {
                    $projects->whereIn('projects.id', $projectIds);
                })
                ->pluck('users.id');

        return $staff
            ->whereIn('users.id', $ownerIds->merge($projectMemberIds)->unique()->values())
            ->get();
    }

    /**
     * @return array<string>|null Null when the user has unrestricted team access.
     */
    public function clientIds(): ?array
    {
        if ($this->resolvedClientIds) {
            return $this->clientIds;
        }

        $this->resolvedClientIds = true;

        if (! $this->isClientUser()) {
            $this->clientIds = null;

            return null;
        }

        $this->clientIds = $this->user->clients()
            ->where('clients.team_id', $this->team->id)
            ->where('clients.status', ClientStatus::Active)
            ->pluck('clients.id')
            ->all();

        return $this->clientIds;
    }

    /**
     * @return array<string>|null Null when the user has unrestricted team access.
     */
    public function assignedProjectIds(): ?array
    {
        if ($this->resolvedProjectIds) {
            return $this->assignedProjectIds;
        }

        $this->resolvedProjectIds = true;

        if (! $this->isRestrictedMember()) {
            $this->assignedProjectIds = null;

            return null;
        }

        $this->assignedProjectIds = $this->user->assignedProjects()
            ->where('projects.team_id', $this->team->id)
            ->pluck('projects.id')
            ->all();

        return $this->assignedProjectIds;
    }

    public function canAccessProject(Project $project): bool
    {
        if ($project->team_id !== $this->team->id) {
            return false;
        }

        if ($this->hasUnrestrictedAccess()) {
            return true;
        }

        $clientIds = $this->clientIds();

        if ($clientIds !== null) {
            return $project->client_id !== null && in_array($project->client_id, $clientIds, true);
        }

        $assignedProjectIds = $this->assignedProjectIds();

        if ($assignedProjectIds !== null) {
            return in_array($project->id, $assignedProjectIds, true);
        }

        return true;
    }

    public function canAccessIssue(Issue $issue): bool
    {
        if ($issue->team_id !== $this->team->id) {
            return false;
        }

        if ($this->hidesPersonalImportedIssue($issue)) {
            return false;
        }

        if ($this->hasUnrestrictedAccess()) {
            return true;
        }

        $clientIds = $this->clientIds();

        if ($clientIds !== null) {
            if ($issue->project_id === null) {
                return false;
            }

            $issue->loadMissing('project');

            return $this->canAccessProject($issue->project);
        }

        $assignedProjectIds = $this->assignedProjectIds();

        if ($assignedProjectIds !== null) {
            if ($issue->created_by === $this->user->id) {
                return true;
            }

            if ($issue->isAssignedTo($this->user)) {
                return true;
            }

            return $issue->project_id !== null && in_array($issue->project_id, $assignedProjectIds, true);
        }

        return true;
    }

    public function canAccessClient(Client $client): bool
    {
        if ($client->team_id !== $this->team->id) {
            return false;
        }

        $clientIds = $this->clientIds();

        if ($clientIds === null) {
            return $this->hasUnrestrictedAccess();
        }

        return in_array($client->id, $clientIds, true);
    }

    /**
     * @param  Builder<Project>|Relation<Project, *, *>  $query
     * @return Builder<Project>|Relation<Project, *, *>
     */
    public function scopeProjects(Builder|Relation $query): Builder|Relation
    {
        if ($this->hasUnrestrictedAccess()) {
            return $query;
        }

        $clientIds = $this->clientIds();

        if ($clientIds !== null) {
            return $query->whereIn('client_id', $clientIds);
        }

        $assignedProjectIds = $this->assignedProjectIds();

        if ($assignedProjectIds !== null) {
            return $query->whereIn('id', $assignedProjectIds);
        }

        return $query;
    }

    /**
     * @param  Builder<Issue>|Relation<Issue, *, *>  $query
     * @return Builder<Issue>|Relation<Issue, *, *>
     */
    public function scopeIssues(Builder|Relation $query): Builder|Relation
    {
        $query = $this->constrainPersonalImportedIssues($query);

        if ($this->hasUnrestrictedAccess()) {
            return $query;
        }

        $clientIds = $this->clientIds();

        if ($clientIds !== null) {
            return $query->whereHas('project', fn (Builder $projects) => $projects->whereIn('client_id', $clientIds));
        }

        $assignedProjectIds = $this->assignedProjectIds();

        if ($assignedProjectIds !== null) {
            return $query->where(function (Builder $issues) use ($assignedProjectIds) {
                $issues->whereIn('project_id', $assignedProjectIds)
                    ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->where('users.id', $this->user->id))
                    ->orWhere('created_by', $this->user->id);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Client>|Relation<Client, *, *>  $query
     * @return Builder<Client>|Relation<Client, *, *>
     */
    public function scopeClients(Builder|Relation $query): Builder|Relation
    {
        $clientIds = $this->clientIds();

        if ($clientIds === null) {
            return $query;
        }

        return $query->whereIn('id', $clientIds);
    }

    protected function hidesPersonalImportedIssue(Issue $issue): bool
    {
        if ($issue->project_id !== null) {
            return false;
        }

        $issue->loadMissing('connection');
        $connection = $issue->connection;

        if ($connection === null || ! $connection->importsPersonalIssues()) {
            return false;
        }

        if ($connection->user_id !== null && $connection->user_id === $this->user->id) {
            return false;
        }

        return ! $issue->isAssignedTo($this->user);
    }

    /**
     * @param  Builder<Issue>|Relation<Issue, *, *>  $query
     * @return Builder<Issue>|Relation<Issue, *, *>
     */
    protected function constrainPersonalImportedIssues(Builder|Relation $query): Builder|Relation
    {
        $userId = $this->user->id;

        return $query->where(function (Builder $issues) use ($userId): void {
            $issues->whereNotNull('project_id')
                ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->where('users.id', $userId))
                ->orWhereDoesntHave('connection', function (Builder $connections) use ($userId): void {
                    $connections->where('provider', Provider::Todoist)
                        ->where(function (Builder $owned) use ($userId): void {
                            $owned->whereNull('user_id')
                                ->orWhere('user_id', '!=', $userId);
                        });
                });
        });
    }
}
