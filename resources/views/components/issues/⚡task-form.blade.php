<?php

use App\Actions\Issues\CreateIssue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $projectId = '';

    public bool $lockProject = false;

    public string $title = '';

    public string $description = '';

    /**
     * @var array<int, int|string>
     */
    public array $assigneeIds = [];

    /**
     * @var array<int, string>
     */
    public array $labelNames = [];

    public bool $publishToGithub = false;

    public function mount(string $projectId = '', bool $lockProject = false): void
    {
        Gate::authorize('create', [Issue::class, $this->team()]);

        $this->projectId = $projectId;
        $this->lockProject = $lockProject;
    }

    public function updatedProjectId(): void
    {
        $this->publishToGithub = false;
    }

    public function create(CreateIssue $action): void
    {
        Gate::authorize('create', [Issue::class, $this->team()]);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'projectId' => ['required', 'uuid'],
            'assigneeIds' => ['array'],
            'assigneeIds.*' => ['integer'],
            'labelNames' => ['array'],
            'labelNames.*' => ['string', 'max:255'],
            'publishToGithub' => ['boolean'],
        ]);

        $project = $this->team()->projects()->findOrFail($validated['projectId']);

        $created = $action->handle(
            $this->team(),
            Auth::user(),
            $project,
            $validated['title'],
            $validated['description'] ?? null,
            $validated['assigneeIds'],
            $validated['labelNames'],
            (bool) $validated['publishToGithub'],
        );

        $lockedProjectId = $this->lockProject ? $this->projectId : '';

        $this->reset('title', 'description', 'assigneeIds', 'labelNames', 'publishToGithub');
        $this->projectId = $lockedProjectId;

        $this->dispatch('task-created');

        if ($created->publishError !== null) {
            Flux::toast(variant: 'warning', text: $created->publishError);

            return;
        }

        Flux::toast(
            variant: 'success',
            text: $created->issue->isLinkedToSource()
                ? __('Task created and published to GitHub.')
                : __('Task created.'),
        );
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
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team()->members()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, \App\Models\Label>
     */
    #[Computed]
    public function labels(): Collection
    {
        return $this->team()->labels()->orderBy('name')->get();
    }

    #[Computed]
    public function linkedRepoName(): ?string
    {
        if ($this->projectId === '') {
            return null;
        }

        return $this->team()
            ->projects()
            ->with('connectedSource')
            ->find($this->projectId)
            ?->connectedSource
            ?->name;
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
    <flux:heading size="lg">{{ __('New task') }}</flux:heading>

    @if ($this->projects->isEmpty())
        <flux:text>{{ __('Create a project before adding a task.') }}</flux:text>
    @else
        <form wire:submit="create" class="space-y-4">
            <flux:input wire:model="title" :label="__('Title')" data-test="task-title" />

            <flux:textarea wire:model="description" :label="__('Description')" rows="4" data-test="task-description" />

            @unless ($lockProject)
                <flux:select wire:model.live="projectId" :label="__('Project')" data-test="task-project">
                    <flux:select.option value="">{{ __('Choose a project') }}</flux:select.option>
                    @foreach ($this->projects as $project)
                        <flux:select.option :value="$project->id" wire:key="task-project-{{ $project->id }}">{{ $project->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endunless

            @if ($this->members->isNotEmpty())
                <div class="space-y-2">
                    <flux:text class="text-sm font-medium">{{ __('Assignees') }}</flux:text>
                    @foreach ($this->members as $member)
                        <label class="flex items-center gap-2 text-sm" wire:key="task-assignee-{{ $member->id }}">
                            <input type="checkbox" value="{{ $member->id }}" wire:model="assigneeIds" data-test="task-assignee">
                            <span>{{ $member->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endif

            @if ($this->labels->isNotEmpty())
                <div class="space-y-2">
                    <flux:text class="text-sm font-medium">{{ __('Labels') }}</flux:text>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->labels as $label)
                            <label class="flex items-center gap-2 text-sm" wire:key="task-label-{{ $label->id }}">
                                <input type="checkbox" value="{{ $label->name }}" wire:model="labelNames" data-test="task-label">
                                <span>{{ $label->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->linkedRepoName)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="publishToGithub" data-test="publish-to-github">
                    <span>{{ __('Also create a GitHub issue on :repo', ['repo' => $this->linkedRepoName]) }}</span>
                </label>
            @endif

            <flux:button variant="primary" type="submit" data-test="create-task">{{ __('Add task') }}</flux:button>
        </form>
    @endif
</div>
