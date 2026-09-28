<?php

use App\Enums\IssueStatus;
use App\Enums\ProjectStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function openCount(): int
    {
        return Issue::query()->whereBelongsTo($this->team())->open()->count();
    }

    #[Computed]
    public function assignedCount(): int
    {
        return Issue::query()
            ->whereBelongsTo($this->team())
            ->open()
            ->whereHas('assignees', fn ($query) => $query->where('users.id', Auth::id()))
            ->count();
    }

    #[Computed]
    public function closedCount(): int
    {
        return Issue::query()->whereBelongsTo($this->team())->closed()->count();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->team()
            ->projects()
            ->where('status', ProjectStatus::Open)
            ->with('connectedSource')
            ->withCount(['issues' => fn ($query) => $query->where('status', IssueStatus::Open)])
            ->orderBy('name')
            ->limit(8)
            ->get();
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
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('Open work for this team.') }}</flux:subheading>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <a href="{{ route('issues.index', ['status' => 'open']) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900" data-test="open-issues-count">
                <flux:text class="text-sm text-zinc-500">{{ __('Open tasks') }}</flux:text>
                <flux:heading size="xl">{{ $this->openCount }}</flux:heading>
            </a>
            <a href="{{ route('issues.index', ['status' => 'open', 'assigneeId' => auth()->id()]) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900" data-test="assigned-issues-count">
                <flux:text class="text-sm text-zinc-500">{{ __('Assigned to me') }}</flux:text>
                <flux:heading size="xl">{{ $this->assignedCount }}</flux:heading>
            </a>
            <a href="{{ route('issues.index', ['status' => 'closed']) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500">{{ __('Closed') }}</flux:text>
                <flux:heading size="xl">{{ $this->closedCount }}</flux:heading>
            </a>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Projects') }}</flux:heading>
            <flux:subheading class="mb-4">{{ __('Open projects and the tasks still in them.') }}</flux:subheading>

            <div class="space-y-2">
                @forelse ($this->projects as $project)
                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-900" wire:key="dash-project-{{ $project->id }}">
                        <div>
                            <span>{{ $project->name }}</span>
                            <flux:text class="text-sm text-zinc-500">{{ $project->connectedSource->name ?? __('No repository') }}</flux:text>
                        </div>
                        <flux:badge color="zinc">{{ $project->issues_count }}</flux:badge>
                    </a>
                @empty
                    <flux:text>{{ __('Create a project to start assigning work.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Labels') }}</flux:heading>
            <flux:subheading class="mb-4">{{ __('Tags on open tasks.') }}</flux:subheading>

            <div class="space-y-2">
                @forelse ($this->labels as $label)
                    <a href="{{ route('issues.index', ['labelId' => $label->id, 'status' => 'open']) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-900" wire:key="dash-label-{{ $label->id }}">
                        <div class="flex items-center gap-2">
                            <span class="size-3 rounded-full" style="background-color: #{{ $label->color }}"></span>
                            <span>{{ $label->name }}</span>
                        </div>
                        <flux:badge color="zinc">{{ $label->issues_count }}</flux:badge>
                    </a>
                @empty
                    <flux:text>{{ __('Labels will appear after you connect a source and sync issues.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>
</div>
