<?php

use App\Enums\IssueStatus;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Queries\TeamIssueQuery;
use App\Services\TeamResourceAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Dashboard')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $clientId = '';

    public function mount(): void
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        if ($team !== null && ! $user->isTeamClient($team)) {
            $this->redirectRoute('workspace', navigate: true);
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedClientId(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function openCount(): int
    {
        return TeamIssueQuery::for(Auth::user(), $this->team())
            ->applyFilters(['status' => 'open', 'clientId' => $this->clientId])
            ->count();
    }

    #[Computed]
    public function assignedCount(): int
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeIssues(Issue::query()->whereBelongsTo($this->team()))
            ->open()
            ->whereHas('assignees', fn ($query) => $query->where('users.id', Auth::id()))
            ->when($this->isAdminUser && $this->clientId === 'internal', fn ($query) => $query->where(function ($inner) {
                $inner->whereNull('project_id')
                    ->orWhereHas('project', fn ($projects) => $projects->whereNull('client_id'));
            }))
            ->when($this->isAdminUser && filled($this->clientId) && $this->clientId !== 'internal', fn ($query) => $query->whereHas(
                'project',
                fn ($projects) => $projects->where('client_id', $this->clientId),
            ))
            ->count();
    }

    #[Computed]
    public function closedCount(): int
    {
        return TeamIssueQuery::for(Auth::user(), $this->team())
            ->applyFilters(['status' => 'closed', 'clientId' => $this->clientId])
            ->count();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeProjects($this->team()->projects())
            ->where('status', ProjectStatus::Open)
            ->withCount(['issues' => function ($query) {
                TeamResourceAccess::for(Auth::user(), $this->team())
                    ->scopeIssues($query->where('status', IssueStatus::Open));
            }])
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeClients($this->team()->clients()->where('status', 'active'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Issue>|null
     */
    #[Computed]
    public function issues(): ?LengthAwarePaginator
    {
        if (! $this->isAdminUser) {
            return null;
        }

        return TeamIssueQuery::for(Auth::user(), $this->team())
            ->applyFilters([
                'status' => $this->status,
                'clientId' => $this->clientId,
            ])
            ->paginate(10);
    }

    #[Computed]
    public function isScopedUser(): bool
    {
        return Auth::user()->isScopedTeamUser($this->team());
    }

    #[Computed]
    public function isAdminUser(): bool
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())->hasUnrestrictedAccess();
    }

    /**
     * @return Collection<int, \App\Models\Label>
     */
    #[Computed]
    public function labels(): Collection
    {
        return $this->team()
            ->labels()
            ->withCount(['issues' => fn ($query) => $query->where('status', IssueStatus::Open)])
            ->orderByDesc('issues_count')
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div>
    <livewire:pages::teams.pending-invitations-modal />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Dashboard') }}</h1>
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ $this->isScopedUser ? __('Your assigned projects and open tasks.') : __('Open work for this team.') }}</p>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <a href="{{ route(auth()->user()->sectionRoute('issues.index'), array_filter(['status' => 'open', 'clientId' => $this->clientId ?: null])) }}" wire:navigate class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:bg-gray-50 dark:border-dark-700 dark:bg-dark-800 dark:hover:bg-dark-700" data-test="open-issues-count">
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Open tasks') }}</p>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->openCount }}</h1>
            </a>
            <a href="{{ route(auth()->user()->sectionRoute('issues.index'), array_filter(['status' => 'open', 'assigneeId' => auth()->id(), 'clientId' => $this->clientId ?: null])) }}" wire:navigate class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:bg-gray-50 dark:border-dark-700 dark:bg-dark-800 dark:hover:bg-dark-700" data-test="assigned-issues-count">
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Assigned to me') }}</p>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->assignedCount }}</h1>
            </a>
            <a href="{{ route(auth()->user()->sectionRoute('issues.index'), array_filter(['status' => 'closed', 'clientId' => $this->clientId ?: null])) }}" wire:navigate class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:bg-gray-50 dark:border-dark-700 dark:bg-dark-800 dark:hover:bg-dark-700">
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Closed') }}</p>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->closedCount }}</h1>
            </a>
        </div>

        @if ($this->isAdminUser)
            <x-card>
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Tasks') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('All team tasks, filterable by client and status.') }}</p>
                    </div>
                    <a href="{{ route(auth()->user()->sectionRoute('issues.index'), array_filter(['status' => $this->status, 'clientId' => $this->clientId ?: null])) }}" wire:navigate class="text-sm text-zinc-500 hover:underline">
                        {{ __('View all tasks') }}
                    </a>
                </div>

                <div class="mb-4 grid gap-3 md:grid-cols-2">
                    <x-select.native wire:model.live="status" data-test="dashboard-issue-status-filter">
                        <option value="open">{{ __('Open') }}</option>
                        <option value="closed">{{ __('Closed') }}</option>
                        <option value="all">{{ __('All') }}</option>
                    </x-select.native>

                    <x-select.native wire:model.live="clientId" data-test="dashboard-issue-client-filter">
                        <option value="">{{ __('All clients') }}</option>
                        <option value="internal">{{ __('Internal') }}</option>
                        @foreach ($this->clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </x-select.native>
                </div>

                <div class="space-y-2">
                    @forelse ($this->issues as $issue)
                        <a href="{{ route(auth()->user()->sectionRoute('issues.show'), $issue) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-dark-700" wire:key="dash-issue-{{ $issue->id }}" data-test="dashboard-issue-row">
                            <div>
                                <span>{{ $issue->title }}</span>
                                <p class="text-sm text-gray-500 dark:text-dark-300">
                                    {{ $issue->project?->client?->name ?? __('Internal') }}
                                    · {{ $issue->project->name ?? __('No project') }}
                                </p>
                            </div>
                            <x-badge color="gray" light :text="$issue->status->label()" />
                        </a>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No tasks match these filters.') }}</p>
                    @endforelse
                </div>

                <div class="mt-4">
                    {{ $this->issues->links() }}
                </div>
            </x-card>
        @endif

        <x-card>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Projects') }}</h2>
            <p class="mb-4 text-sm text-gray-500 dark:text-dark-300">{{ __('Open projects and the tasks still in them.') }}</p>

            <div class="space-y-2">
                @forelse ($this->projects as $project)
                    <a href="{{ route(auth()->user()->sectionRoute('projects.show'), $project) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-dark-700" wire:key="dash-project-{{ $project->id }}">
                        <div>
                            <span>{{ $project->name }}</span>
                        </div>
                        <x-badge color="gray" light :text="$project->issues_count" />
                    </a>
                @empty
                    <p class="text-sm text-gray-500 dark:text-dark-300">{{ $this->isScopedUser ? __('No projects assigned to you yet.') : __('Create a project to start assigning work.') }}</p>
                @endforelse
            </div>
        </x-card>

        @unless ($this->isScopedUser)
        <x-card>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Labels') }}</h2>
            <p class="mb-4 text-sm text-gray-500 dark:text-dark-300">{{ __('Tags on open tasks.') }}</p>

            <div class="space-y-2">
                @forelse ($this->labels as $label)
                    <a href="{{ route(auth()->user()->sectionRoute('issues.index'), ['labelId' => $label->id, 'status' => 'open']) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-dark-700" wire:key="dash-label-{{ $label->id }}">
                        <div class="flex items-center gap-2">
                            <span class="size-3 rounded-full" style="background-color: #{{ $label->color }}"></span>
                            <span>{{ $label->name }}</span>
                        </div>
                        <x-badge color="gray" light :text="$label->issues_count" />
                    </a>
                @empty
                    <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Labels will appear after you connect a source and sync issues.') }}</p>
                @endforelse
            </div>
        </x-card>
        @endunless
    </div>
</div>
