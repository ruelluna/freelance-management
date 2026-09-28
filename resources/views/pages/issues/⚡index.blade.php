<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    /**
     * @return LengthAwarePaginator<int, Issue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return Issue::query()
            ->whereBelongsTo($this->team())
            ->with(['labels', 'assignees', 'connectedSource', 'project'])
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where('title', 'like', '%'.$this->search.'%'))
            ->when($this->labelId, fn ($query) => $query->whereHas('labels', fn ($labels) => $labels->where('labels.id', $this->labelId)))
            ->when($this->sourceId, fn ($query) => $query->where('connected_source_id', $this->sourceId))
            ->when($this->assigneeId, fn ($query) => $query->whereHas('assignees', fn ($assignees) => $assignees->where('users.id', $this->assigneeId)))
            ->when($this->projectId, fn ($query) => $query->where('project_id', $this->projectId))
            ->latest('updated_at')
            ->latest('id')
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
        return $this->team()->projects()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, \App\Models\ConnectedSource>
     */
    #[Computed]
    public function sources(): Collection
    {
        return $this->team()->connections()->with('sources')->get()->pluck('sources')->flatten();
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team()->members()->orderBy('name')->get();
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Auth::user()->can('create', [Issue::class, $this->team()]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl">{{ __('Tasks') }}</flux:heading>
            <flux:subheading>{{ __('Work for this team, including tasks you create here and issues synced from a connection.') }}</flux:subheading>
        </div>

        @if ($this->canCreate)
            <livewire:issues.task-form />
        @endif

        <div class="grid gap-3 md:grid-cols-3">
            <flux:input wire:model.live.debounce.400ms="search" :placeholder="__('Search tasks')" data-test="issue-search" />

            <flux:select wire:model.live="status" data-test="issue-status-filter">
                <flux:select.option value="open">{{ __('Open') }}</flux:select.option>
                <flux:select.option value="closed">{{ __('Closed') }}</flux:select.option>
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="projectId" data-test="issue-project-filter">
                <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
                @foreach ($this->projects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="labelId" data-test="issue-label-filter">
                <flux:select.option value="">{{ __('All labels') }}</flux:select.option>
                @foreach ($this->labels as $label)
                    <flux:select.option :value="$label->id">{{ $label->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="sourceId" data-test="issue-source-filter">
                <flux:select.option value="">{{ __('All sources') }}</flux:select.option>
                @foreach ($this->sources as $source)
                    <flux:select.option :value="$source->id">{{ $source->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="assigneeId" data-test="issue-assignee-filter">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                @foreach ($this->members as $member)
                    <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="space-y-3">
            @forelse ($this->issues as $issue)
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 md:flex-row md:items-center md:justify-between" wire:key="issue-{{ $issue->id }}" data-test="issue-row">
                    <div class="min-w-0">
                        <a href="{{ route('issues.show', $issue) }}" class="font-medium hover:underline" wire:navigate data-test="issue-link">
                            {{ $issue->title }}
                        </a>
                        <flux:text class="text-sm text-zinc-500">
                            {{ $issue->project->name ?? __('No project') }}
                            · {{ $issue->connectedSource->name ?? __('Local task') }}
                            @if ($issue->number)
                                · #{{ $issue->number }}
                            @endif
                            · {{ $issue->assignees->pluck('name')->join(', ') ?: __('Unassigned') }}
                        </flux:text>
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($issue->labels as $label)
                                <flux:badge color="zinc">{{ $label->name }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                    <flux:badge :color="$issue->status === \App\Enums\IssueStatus::Open ? 'lime' : 'zinc'">
                        {{ $issue->status->label() }}
                    </flux:badge>
                </div>
            @empty
                <flux:text class="py-6 text-center">{{ __('No tasks match these filters.') }}</flux:text>
            @endforelse
        </div>

        <div>
            {{ $this->issues->links() }}
        </div>
    </div>
