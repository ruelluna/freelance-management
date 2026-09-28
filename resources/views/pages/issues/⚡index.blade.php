<?php

use App\Actions\Issues\AssignIssue;
use App\Actions\Issues\AssignIssueClient;
use App\Actions\Issues\AssignIssueProject;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Queries\TeamIssueQuery;
use App\Services\TeamResourceAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Tasks')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $clientId = '';

    #[Url]
    public string $labelId = '';

    #[Url]
    public string $sourceId = '';

    #[Url]
    public string $assigneeId = '';

    #[Url]
    public string $projectId = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', [Issue::class, $this->team()]);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedClientId(): void
    {
        $this->resetPage();
    }

    public function updatedLabelId(): void
    {
        $this->resetPage();
    }

    public function updatedSourceId(): void
    {
        $this->resetPage();
    }

    public function updatedAssigneeId(): void
    {
        $this->resetPage();
    }

    public function updatedProjectId(): void
    {
        $this->resetPage();
    }

    #[On('task-created')]
    public function refreshTasks(): void
    {
        unset($this->issues);
    }

    public function assignTask(string $issueId, string $userId, AssignIssue $action): void
    {
        $issue = $this->team()->issues()->findOrFail($issueId);

        Gate::authorize('assign', $issue);

        $action->handle($issue, $userId === '' ? [] : [$userId]);

        unset($this->issues);
    }

    public function assignClient(string $issueId, string $clientId, AssignIssueClient $action): void
    {
        $issue = $this->team()->issues()->findOrFail($issueId);

        Gate::authorize('update', $issue);

        $client = null;

        if ($clientId !== '') {
            $client = TeamResourceAccess::for(Auth::user(), $this->team())
                ->scopeClients($this->team()->clients())
                ->find($clientId);

            if ($client === null) {
                throw ValidationException::withMessages([
                    'clientId' => __('Choose a client on this team.'),
                ]);
            }
        }

        $action->handle($issue, $client);

        unset($this->issues);
    }

    public function assignProject(string $issueId, string $projectId, AssignIssueProject $action): void
    {
        $issue = $this->team()->issues()->findOrFail($issueId);

        Gate::authorize('update', $issue);

        $project = null;

        if ($projectId !== '') {
            $project = TeamResourceAccess::for(Auth::user(), $this->team())
                ->scopeProjects($this->team()->projects())
                ->find($projectId);

            if ($project === null) {
                throw ValidationException::withMessages([
                    'projectId' => __('Choose a project on this team.'),
                ]);
            }
        }

        $action->handle($issue, $project);

        unset($this->issues);
    }

    /**
     * @return LengthAwarePaginator<int, Issue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return TeamIssueQuery::for(Auth::user(), $this->team())
            ->applyFilters([
                'search' => $this->search,
                'status' => $this->status,
                'clientId' => $this->clientId,
                'projectId' => $this->projectId,
                'labelId' => $this->isClientUser() ? '' : $this->labelId,
                'sourceId' => $this->sourceId,
                'assigneeId' => $this->assigneeId,
            ])
            ->paginate(20);
    }

    /**
     * @return Collection<int, \App\Models\Label>
     */
    #[Computed]
    public function labels(): Collection
    {
        return $this->team()->labels()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeProjects($this->team()->projects())
            ->orderBy('name')
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
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())->assignableUsers();
    }

    #[Computed]
    public function isClientUser(): bool
    {
        return Auth::user()->isTeamClient($this->team());
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Auth::user()->can('create', [Issue::class, $this->team()]);
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

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Tasks') }}</h1>
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ $this->isScopedUser ? __('Tasks on your projects.') : __('Work for this team, including tasks you create here and issues synced from a connection.') }}</p>
            </div>
            @if ($this->canCreate)
                <livewire:issues.task-form />
            @endif
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            <x-input wire:model.live.debounce.400ms="search" :placeholder="__('Search tasks')" data-test="issue-search" />

            <x-select.native wire:model.live="status" data-test="issue-status-filter">
                <option value="open">{{ __('Open') }}</option>
                <option value="closed">{{ __('Closed') }}</option>
                <option value="all">{{ __('All') }}</option>
            </x-select.native>

            <x-select.native wire:model.live="projectId" data-test="issue-project-filter">
                <option value="">{{ __('All projects') }}</option>
                @foreach ($this->projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </x-select.native>

            @if ($this->isAdminUser)
                <x-select.native wire:model.live="clientId" data-test="issue-client-filter">
                    <option value="">{{ __('All clients') }}</option>
                    <option value="internal">{{ __('Internal') }}</option>
                    @foreach ($this->clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </x-select.native>
            @endif

            @unless ($this->isScopedUser)
                <x-select.native wire:model.live="labelId" data-test="issue-label-filter">
                    <option value="">{{ __('All labels') }}</option>
                    @foreach ($this->labels as $label)
                        <option value="{{ $label->id }}">{{ $label->name }}</option>
                    @endforeach
                </x-select.native>

                <x-select.native wire:model.live="sourceId" data-test="issue-source-filter">
                    <option value="">{{ __('All sources') }}</option>
                    <option value="manual">{{ __('Manual') }}</option>
                    <option value="todoist">{{ __('Todoist') }}</option>
                    <option value="coda">{{ __('Coda') }}</option>
                    <option value="github">{{ __('GitHub') }}</option>
                </x-select.native>
            @endunless

            <x-select.native wire:model.live="assigneeId" data-test="issue-assignee-filter">
                <option value="">{{ __('Anyone') }}</option>
                @foreach ($this->members as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </x-select.native>
        </div>

        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-dark-700">
            <table class="min-w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-medium tracking-wide text-gray-500 uppercase dark:border-dark-700 dark:bg-dark-800 dark:text-dark-300">
                    <tr>
                        <th class="px-3 py-2">{{ __('Task') }}</th>
                        <th class="px-3 py-2">{{ __('Status') }}</th>
                        @if ($this->isAdminUser)
                            <th class="px-3 py-2">{{ __('Client') }}</th>
                        @endif
                        <th class="px-3 py-2">{{ __('Project') }}</th>
                        @unless (auth()->user()->isTeamClient(auth()->user()->currentTeam))
                            <th class="px-3 py-2">{{ __('Source') }}</th>
                            <th class="px-3 py-2">{{ __('Created by') }}</th>
                        @endunless
                        <th class="px-3 py-2">{{ __('Assignee') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-dark-700">
                    @forelse ($this->issues as $issue)
                        <tr wire:key="issue-{{ $issue->id }}" data-test="issue-row" class="hover:bg-zinc-50 dark:hover:bg-dark-800">
                            <td class="max-w-md px-3 py-2">
                                <a href="{{ route(auth()->user()->sectionRoute('issues.show'), $issue) }}" class="font-medium hover:underline" wire:navigate data-test="issue-link">
                                    {{ $issue->title }}
                                </a>
                                @if ($issue->number)
                                    <span class="text-gray-500 dark:text-dark-300">#{{ $issue->number }}</span>
                                @endif
                                @if (! $this->isClientUser && $issue->labels->isNotEmpty())
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($issue->labels as $label)
                                            <x-badge sm color="gray" light :text="$label->name" />
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <x-badge sm light :color="$issue->status === \App\Enums\IssueStatus::Open ? 'green' : 'gray'" :text="$issue->status->label()" />
                            </td>
                            @if ($this->isAdminUser)
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @can('update', $issue)
                                        <select
                                            wire:change="assignClient('{{ $issue->id }}', $event.target.value)"
                                            data-test="table-client"
                                            class="w-full max-w-48 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm text-gray-700 dark:border-dark-600 dark:bg-dark-900 dark:text-white"
                                        >
                                            <option value="" @selected(($issue->client_id ?? $issue->project?->client_id) === null)>{{ __('Internal') }}</option>
                                            @foreach ($this->clients as $client)
                                                <option value="{{ $client->id }}" @selected(($issue->client_id ?? $issue->project?->client_id) === $client->id)>{{ $client->name }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-gray-500 dark:text-dark-300">{{ $issue->client?->name ?? $issue->project?->client?->name ?? __('Internal') }}</span>
                                    @endcan
                                </td>
                            @endif
                            <td class="px-3 py-2 whitespace-nowrap">
                                @can('update', $issue)
                                    <select
                                        wire:change="assignProject('{{ $issue->id }}', $event.target.value)"
                                        data-test="table-project"
                                        class="w-full max-w-48 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm text-gray-700 dark:border-dark-600 dark:bg-dark-900 dark:text-white"
                                    >
                                        <option value="">{{ __('No project') }}</option>
                                        @foreach ($this->projects as $project)
                                            <option value="{{ $project->id }}" @selected($issue->project_id === $project->id)>{{ $project->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-gray-500 dark:text-dark-300">{{ $issue->project->name ?? __('No project') }}</span>
                                @endcan
                            </td>
                            @unless (auth()->user()->isTeamClient(auth()->user()->currentTeam))
                                <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-dark-300" data-test="table-source">{{ $issue->sourceLabel() }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-dark-300" data-test="table-creator">{{ $issue->connection_id === null ? ($issue->creator?->name ?? '—') : '—' }}</td>
                            @endunless
                            <td class="px-3 py-2 whitespace-nowrap">
                                @can('assign', $issue)
                                    <select
                                        wire:change="assignTask('{{ $issue->id }}', $event.target.value)"
                                        data-test="table-assignee"
                                        class="w-full max-w-48 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm text-gray-700 dark:border-dark-600 dark:bg-dark-900 dark:text-white"
                                    >
                                        <option value="">{{ __('Unassigned') }}</option>
                                        @if ($issue->assignees->count() > 1)
                                            <option value="multiple" selected disabled>{{ $issue->assignees->pluck('name')->join(', ') }}</option>
                                        @endif
                                        @foreach ($this->members as $member)
                                            <option
                                                value="{{ $member->id }}"
                                                @selected($issue->assignees->count() === 1 && $issue->assignees->first()->id === $member->id)
                                            >{{ $member->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-gray-500 dark:text-dark-300">{{ $issue->assignees->pluck('name')->join(', ') ?: __('Unassigned') }}</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $this->isAdminUser ? 7 : (auth()->user()->isTeamClient(auth()->user()->currentTeam) ? 4 : 6) }}" class="px-3 py-6 text-center text-gray-500 dark:text-dark-300">
                                {{ __('No tasks match these filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            {{ $this->issues->links() }}
        </div>
    </div>
