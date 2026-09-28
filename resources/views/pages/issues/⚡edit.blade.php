<?php

use App\Actions\Issues\AssignIssue;
use App\Actions\Issues\DeleteIssue;
use App\Actions\Issues\UpdateIssue;
use App\Data\Integrations\IssueUpdate;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Services\TeamResourceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Edit task')] class extends Component {
    use Interactions;

    public Issue $issue;

    public string $title = '';

    public string $description = '';

    public string $projectId = '';

    /**
     * @var array<int, int>
     */
    public array $assigneeIds = [];

    /**
     * @var array<int, string>
     */
    public array $labelNames = [];

    public function mount(Issue $issue): void
    {
        abort_unless($issue->team_id === $this->team()->id, 404);

        Gate::authorize('view', $issue);
        abort_unless($this->userCanEdit($issue), 403);

        $this->issue = $issue->load($this->issueRelations());
        $this->fillFromIssue();
    }

    public function saveDetails(UpdateIssue $action): void
    {
        Gate::authorize('update', $this->issue);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'projectId' => ['nullable', 'uuid'],
        ]);

        if (filled($validated['projectId'] ?? null)) {
            $project = TeamResourceAccess::for(Auth::user(), $this->team())
                ->scopeProjects($this->team()->projects())
                ->findOrFail($validated['projectId']);

            Gate::authorize('createOnProject', $project);
            $this->issue->update(['project_id' => $project->id]);
        } elseif (! Auth::user()->isScopedTeamUser($this->team())) {
            $this->issue->update(['project_id' => null]);
        }

        $action->handle($this->issue, new IssueUpdate(
            title: $validated['title'],
            body: $validated['description'] ?? '',
        ));

        $this->refreshIssue();
        $this->fillFromIssue();

        $this->toast()->success(__('Task saved.'))->send();
    }

    public function delete(DeleteIssue $action): void
    {
        Gate::authorize('delete', $this->issue);

        $action->handle($this->issue);

        $this->redirect(route(Auth::user()->sectionRoute('issues.index')), navigate: true);
    }

    public function toggleStatus(UpdateIssue $action): void
    {
        Gate::authorize('update', $this->issue);

        $next = $this->issue->status === IssueStatus::Open
            ? IssueStatus::Closed
            : IssueStatus::Open;

        $action->handle($this->issue, new IssueUpdate(status: $next->value));
        $this->refreshIssue();

        $this->toast()->success(__('Issue :status.', ['status' => $next->label()]))->send();
    }

    public function saveAssignees(AssignIssue $action): void
    {
        Gate::authorize('assign', $this->issue);

        $action->handle($this->issue, $this->assigneeIds);
        $this->refreshIssue();

        $this->toast()->success(__('Assignees updated.'))->send();
    }

    public function saveLabels(UpdateIssue $action): void
    {
        Gate::authorize('update', $this->issue);
        abort_if(Auth::user()->isTeamClient($this->team()), 403);

        $action->handle($this->issue, new IssueUpdate(labelNames: $this->labelNames));
        $this->refreshIssue();
        $this->labelNames = $this->issue->labels->pluck('name')->all();

        $this->toast()->success(__('Labels updated.'))->send();
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
    public function canUpdate(): bool
    {
        return Auth::user()->can('update', $this->issue);
    }

    #[Computed]
    public function canAssign(): bool
    {
        return Auth::user()->can('assign', $this->issue);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->can('delete', $this->issue);
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

    protected function fillFromIssue(): void
    {
        $staffIds = TeamResourceAccess::for(Auth::user(), $this->team())->assignableUsers()->pluck('id');

        $this->assigneeIds = $this->issue->assignees
            ->pluck('id')
            ->intersect($staffIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        if (! Auth::user()->isTeamClient($this->team())) {
            $this->labelNames = $this->issue->labels->pluck('name')->all();
        }
        $this->title = $this->issue->title;
        $this->description = $this->issue->body ?? '';
        $this->projectId = $this->issue->project_id ?? '';
    }

    protected function refreshIssue(): void
    {
        $this->issue = $this->issue->fresh($this->issueRelations());
    }

    /**
     * @return array<int, string>
     */
    protected function issueRelations(): array
    {
        $relations = [
            'assignees',
            'project',
        ];

        if (! Auth::user()->isTeamClient($this->team())) {
            array_unshift($relations, 'labels');
        }

        return $relations;
    }

    protected function userCanEdit(Issue $issue): bool
    {
        $user = Auth::user();

        return $user->can('update', $issue) || $user->can('assign', $issue);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route(auth()->user()->sectionRoute('issues.show'), $issue) }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to task') }}</a>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Edit task') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">{{ $issue->title }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-badge light data-test="issue-status" :color="$issue->status === \App\Enums\IssueStatus::Open ? 'green' : 'gray'" :text="$issue->status->label()" />
            @if ($this->canUpdate)
                <x-button outline wire:click="toggleStatus" data-test="toggle-issue-status" :text="$issue->status === \App\Enums\IssueStatus::Open ? __('Close') : __('Reopen')" />
            @endif
            @if ($this->canDelete)
                <x-button outline x-on:click="$tsui.open.modal('delete-task')" data-test="delete-task" :text="__('Delete')" />
            @endif
        </div>
    </div>

    @if ($this->canUpdate)
        <x-card>
            <form wire:submit="saveDetails" class="space-y-4">
                <x-input wire:model="title" :label="__('Title')" data-test="edit-task-title" />
                <x-editor markdown wire:model="description" :label="__('Description')" min-height="12rem" data-test="edit-task-description" />
                <x-select.native wire:model="projectId" :label="__('Project')" data-test="edit-task-project">
                    @unless ($this->isScopedUser)
                        <option value="">{{ __('No project') }}</option>
                    @endunless
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </x-select.native>
                <x-button submit data-test="save-task" :text="__('Save task')" />
            </form>
        </x-card>
    @endif

    @if ($this->canDelete)
        <x-modal id="delete-task" :title="__('Delete this task?')" center size="lg">
            <form id="delete-task-form" wire:submit="delete">
                <p class="text-sm text-gray-500 dark:text-dark-300">
                    @if ($issue->isLinkedToSource())
                        {{ __('This removes the task from this app. The GitHub issue stays where it is.') }}
                    @else
                        {{ __('This removes the task from this app.') }}
                    @endif
                </p>
            </form>
            <x-slot:footer>
                <div class="flex w-full justify-end gap-2">
                    <x-button outline x-on:click="$tsui.close.modal('delete-task')" :text="__('Cancel')" />
                    <x-button submit form="delete-task-form" color="red" data-test="delete-task-confirm" :text="__('Delete')" />
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        @if ($this->canUpdate && ! $this->isClientUser)
            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Labels') }}</h2>
                <form wire:submit="saveLabels" class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->labels as $label)
                            <label class="flex items-center gap-2 text-sm" wire:key="label-{{ $label->id }}">
                                <input type="checkbox" value="{{ $label->name }}" wire:model="labelNames">
                                <span>{{ $label->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-button outline sm submit data-test="save-labels" :text="__('Save labels')" />
                </form>
            </div>
        @endif

        @if ($this->canAssign)
            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Assignees') }}</h2>
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ $this->isClientUser ? __('The account owner and people on your projects.') : __('You and your employees.') }}</p>
                <form wire:submit="saveAssignees" class="space-y-3">
                    <div class="space-y-2">
                        @foreach ($this->members as $member)
                            <label class="flex items-center gap-2 text-sm" wire:key="assignee-{{ $member->id }}">
                                <input type="checkbox" value="{{ $member->id }}" wire:model="assigneeIds" data-test="assignee-checkbox">
                                <span>{{ $member->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-button outline sm submit data-test="save-assignees" :text="__('Save assignees')" />
                </form>
            </div>
        @endif
    </div>
</div>
