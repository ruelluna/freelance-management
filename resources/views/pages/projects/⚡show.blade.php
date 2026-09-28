<?php

use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Enums\ProjectStatus;
use App\Enums\Provider;
use App\Models\ConnectedSource;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Project')] class extends Component {
    public Project $project;

    public string $name = '';

    public string $description = '';

    public string $connectedSourceId = '';

    public function mount(Project $project): void
    {
        abort_unless($project->team_id === $this->team()->id, 404);

        Gate::authorize('view', $project);

        $this->project = $project->load('connectedSource');
        $this->fillFromProject();
    }

    public function save(UpdateProject $action): void
    {
        Gate::authorize('update', $this->project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'connectedSourceId' => ['nullable', 'uuid'],
        ]);

        $this->project = $action->handle(
            $this->project,
            $validated['name'],
            $validated['description'] ?? null,
            $this->project->status,
            $validated['connectedSourceId'] !== '' ? $validated['connectedSourceId'] : null,
        );

        $this->fillFromProject();

        Flux::toast(variant: 'success', text: __('Project saved.'));
    }

    public function toggleStatus(UpdateProject $action): void
    {
        Gate::authorize('update', $this->project);

        $next = $this->project->status === ProjectStatus::Open
            ? ProjectStatus::Closed
            : ProjectStatus::Open;

        $this->project = $action->handle(
            $this->project,
            $this->project->name,
            $this->project->description,
            $next,
            $this->project->connected_source_id,
        );

        Flux::toast(variant: 'success', text: __('Project :status.', ['status' => $next->label()]));
    }

    public function delete(DeleteProject $action): void
    {
        Gate::authorize('delete', $this->project);

        $action->handle($this->project);

        $this->redirect(route('projects.index'), navigate: true);
    }

    #[On('task-created')]
    public function refreshTasks(): void
    {
        unset($this->tasks);
    }

    /**
     * @return Collection<int, Issue>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->project->issues()
            ->with(['assignees', 'labels'])
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, ConnectedSource>
     */
    #[Computed]
    public function githubSources(): Collection
    {
        return ConnectedSource::query()
            ->whereBelongsTo($this->team())
            ->whereHas('connection', fn ($query) => $query->where('provider', Provider::Github))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('update', $this->project);
    }

    #[Computed]
    public function canCreateTask(): bool
    {
        return Auth::user()->can('create', [Issue::class, $this->team()]);
    }

    protected function fillFromProject(): void
    {
        $this->name = $this->project->name;
        $this->description = $this->project->description ?? '';
        $this->connectedSourceId = $this->project->connected_source_id ?? '';
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('projects.index') }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to projects') }}</a>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <flux:heading size="xl">{{ $project->name }}</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500">
                {{ $project->connectedSource->name ?? __('No repository') }}
            </flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:badge :color="$project->status === \App\Enums\ProjectStatus::Open ? 'lime' : 'zinc'" data-test="project-status">
                {{ $project->status->label() }}
            </flux:badge>
            @if ($this->canManage)
                <flux:button variant="filled" wire:click="toggleStatus" data-test="toggle-project-status">
                    {{ $project->status === \App\Enums\ProjectStatus::Open ? __('Close') : __('Reopen') }}
                </flux:button>
                <flux:modal.trigger name="delete-project">
                    <flux:button variant="ghost" data-test="delete-project-page">{{ __('Delete') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @if ($project->description)
        <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            {{ $project->description }}
        </div>
    @endif

    @if ($this->canManage)
        <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Edit project') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" data-test="edit-project-name" />
            <flux:textarea wire:model="description" :label="__('Description')" rows="3" data-test="edit-project-description" />
            <flux:select wire:model="connectedSourceId" :label="__('GitHub repository')" data-test="edit-project-repo">
                <flux:select.option value="">{{ __('No repository') }}</flux:select.option>
                @foreach ($this->githubSources as $source)
                    <flux:select.option :value="$source->id">{{ $source->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text class="text-sm text-zinc-500">{{ __('Changing the repository does not move tasks already published.') }}</flux:text>
            <flux:button variant="primary" type="submit" data-test="save-project">{{ __('Save project') }}</flux:button>
        </form>

        <flux:modal name="delete-project" class="max-w-lg">
            <form wire:submit="delete" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $project->name]) }}</flux:heading>
                    <flux:subheading>{{ __('Tasks in this project stay in the inbox, without a project.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="delete-project-page-confirm">{{ __('Delete') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->canCreateTask)
        <livewire:issues.task-form :project-id="$project->id" :lock-project="true" :key="'task-form-'.$project->id" />
    @endif

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Tasks') }}</flux:heading>
        @forelse ($this->tasks as $task)
            <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="project-task-{{ $task->id }}" data-test="project-task">
                <div>
                    <a href="{{ route('issues.show', $task) }}" class="font-medium hover:underline" wire:navigate>{{ $task->title }}</a>
                    <flux:text class="text-sm text-zinc-500">
                        {{ $task->assignees->pluck('name')->join(', ') ?: __('Unassigned') }}
                    </flux:text>
                </div>
                <flux:badge :color="$task->status === \App\Enums\IssueStatus::Open ? 'lime' : 'zinc'">
                    {{ $task->status->label() }}
                </flux:badge>
            </div>
        @empty
            <flux:text>{{ __('No tasks in this project yet.') }}</flux:text>
        @endforelse
    </div>
</div>
