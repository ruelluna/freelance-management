<?php

use App\Actions\Issues\CreateIssue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Services\TeamResourceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

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

        $project = TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeProjects($this->team()->projects())
            ->findOrFail($validated['projectId']);

        Gate::authorize('createOnProject', $project);

        $created = $action->handle(
            $this->team(),
            Auth::user(),
            $project,
            $validated['title'],
            $validated['description'] ?? null,
            $validated['assigneeIds'],
            $this->isClientUser() ? [] : $validated['labelNames'],
            (bool) $validated['publishToGithub'],
        );

        $lockedProjectId = $this->lockProject ? $this->projectId : '';

        $this->reset('title', 'description', 'assigneeIds', 'labelNames', 'publishToGithub');
        $this->projectId = $lockedProjectId;

        $this->dispatch('task-created');
        $this->js("\$tsui.close.modal('new-task')");

        if ($created->publishError !== null) {
            $this->toast()->warning($created->publishError)->send();

            return;
        }

        $this->toast()->success(
            $created->issue->isLinkedToSource()
                ? __('Task created and published to GitHub.')
                : __('Task created.'),
        )->send();
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

    #[Computed]
    public function isScopedUser(): bool
    {
        return Auth::user()->isScopedTeamUser($this->team());
    }

    #[Computed]
    public function isClientUser(): bool
    {
        return Auth::user()->isTeamClient($this->team());
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())->assignableUsers();
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

        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeProjects($this->team()->projects())
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

<div>
    <x-button icon="plus" x-on:click="$tsui.open.modal('new-task')" data-test="open-new-task" :text="__('New task')" />

    <x-modal id="new-task" :title="__('New task')" center size="3xl" scrollable>
        @if ($errors->isNotEmpty())
            <div x-init="$tsui.open.modal('new-task')"></div>
        @endif

        @if ($this->projects->isEmpty())
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Create a project before adding a task.') }}</p>
        @else
            <form id="new-task-form" wire:submit="create" class="space-y-4">
                <x-input wire:model="title" :label="__('Title')" data-test="task-title" />

                <livewire:markdown-editor wire:model="description" :label="__('Description')" min-height="8rem" test-id="task-description" />

                @unless ($lockProject)
                    <x-select.native wire:model.live="projectId" :label="__('Project')" data-test="task-project">
                        <option value="">{{ __('Choose a project') }}</option>
                        @foreach ($this->projects as $project)
                            <option value="{{ $project->id }}" wire:key="task-project-{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </x-select.native>
                @endunless

                @if ($this->members->isNotEmpty())
                    <div class="space-y-2">
                        <p class="text-sm font-medium text-gray-500 dark:text-dark-300">{{ __('Assignees') }}</p>
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ $this->isClientUser ? __('The account owner and people on your projects.') : __('You and your employees.') }}</p>
                        @foreach ($this->members as $member)
                            <label class="flex items-center gap-2 text-sm" wire:key="task-assignee-{{ $member->id }}">
                                <input type="checkbox" value="{{ $member->id }}" wire:model="assigneeIds" data-test="task-assignee">
                                <span>{{ $member->name }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                @if (! $this->isClientUser && $this->labels->isNotEmpty())
                    <div class="space-y-2">
                        <p class="text-sm font-medium text-gray-500 dark:text-dark-300">{{ __('Labels') }}</p>
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

                @if ($this->linkedRepoName && ! $this->isScopedUser)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="publishToGithub" data-test="publish-to-github">
                        <span>{{ __('Also create a GitHub issue on :repo', ['repo' => $this->linkedRepoName]) }}</span>
                    </label>
                @endif
            </form>
        @endif

        <x-slot:footer>
            <div class="flex w-full justify-end gap-2">
                <x-button outline x-on:click="$tsui.close.modal('new-task')" :text="__('Cancel')" />
                @if ($this->projects->isNotEmpty())
                    <x-button submit form="new-task-form" data-test="create-task" :text="__('Add task')" />
                @endif
            </div>
        </x-slot:footer>
    </x-modal>
</div>
