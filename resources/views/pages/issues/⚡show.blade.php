<?php

use App\Actions\Issues\AddIssueComment;
use App\Actions\Issues\AssignIssue;
use App\Actions\Issues\DeleteIssue;
use App\Actions\Issues\RefreshIssueFromRemote;
use App\Actions\Issues\UpdateIssue;
use App\Data\Integrations\IssueUpdate;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Task')] class extends Component {
    public Issue $issue;

    public string $title = '';

    public string $description = '';

    public string $projectId = '';

    public string $body = '';

    /**
     * @var array<int, int>
     */
    public array $assigneeIds = [];

    /**
     * @var array<int, string>
     */
    public array $labelNames = [];

    public function mount(Issue $issue, RefreshIssueFromRemote $refresh): void
    {
        abort_unless($issue->team_id === $this->team()->id, 404);

        Gate::authorize('view', $issue);

        $this->issue = $issue->load(['labels', 'assignees', 'comments.user', 'connectedSource', 'connection', 'project']);

        if ($this->issue->isLinkedToSource()) {
            $stale = $this->issue->last_synced_at === null
                || $this->issue->last_synced_at->lte(now()->subSeconds(60));

            if ($stale) {
                if (! $refresh->handle($this->issue)) {
                    Flux::toast(variant: 'warning', text: __('Could not refresh from :source. Showing the last saved copy.', [
                        'source' => $this->issue->connection->provider->label(),
                    ]));
                }

                $this->refreshIssue();
            }
        }

        $this->fillFromIssue();
    }

    public function refreshFromRemote(RefreshIssueFromRemote $refresh): void
    {
        Gate::authorize('view', $this->issue);

        if (! $this->issue->isLinkedToSource()) {
            return;
        }

        $ok = $refresh->handle($this->issue);

        $this->refreshIssue();
        $this->fillFromIssue();

        if ($ok) {
            Flux::toast(variant: 'success', text: __('Issue updated from :source.', [
                'source' => $this->issue->connection->provider->label(),
            ]));

            return;
        }

        Flux::toast(variant: 'warning', text: __('Could not refresh from :source. Showing the last saved copy.', [
            'source' => $this->issue->connection->provider->label(),
        ]));
    }

    public function addComment(AddIssueComment $action): void
    {
        Gate::authorize('comment', $this->issue);

        $this->validate([
            'body' => ['required', 'string', 'min:1', 'max:65535'],
        ]);

        $action->handle($this->issue, Auth::user(), $this->body);

        $this->reset('body');
        $this->refreshIssue();

        if ($this->issue->isLinkedToSource()) {
            Flux::toast(variant: 'success', text: __('Comment sent to :source.', [
                'source' => $this->issue->connection->provider->label(),
            ]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Comment added.'));
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
            $project = $this->team()->projects()->findOrFail($validated['projectId']);
            $this->issue->update(['project_id' => $project->id]);
        } else {
            $this->issue->update(['project_id' => null]);
        }

        $action->handle($this->issue, new IssueUpdate(
            title: $validated['title'],
            body: $validated['description'] ?? '',
        ));

        $this->refreshIssue();
        $this->fillFromIssue();

        Flux::toast(variant: 'success', text: __('Task saved.'));
    }

    public function delete(DeleteIssue $action): void
    {
        Gate::authorize('delete', $this->issue);

        $action->handle($this->issue);

        $this->redirect(route('issues.index'), navigate: true);
    }

    public function toggleStatus(UpdateIssue $action): void
    {
        Gate::authorize('update', $this->issue);

        $next = $this->issue->status === IssueStatus::Open
            ? IssueStatus::Closed
            : IssueStatus::Open;

        $action->handle($this->issue, new IssueUpdate(status: $next->value));
        $this->refreshIssue();

        Flux::toast(variant: 'success', text: __('Issue :status.', ['status' => $next->label()]));
    }

    public function saveAssignees(AssignIssue $action): void
    {
        Gate::authorize('assign', $this->issue);

        $action->handle($this->issue, $this->assigneeIds);
        $this->refreshIssue();

        Flux::toast(variant: 'success', text: __('Assignees updated.'));
    }

    public function saveLabels(UpdateIssue $action): void
    {
        Gate::authorize('update', $this->issue);

        $action->handle($this->issue, new IssueUpdate(labelNames: $this->labelNames));
        $this->refreshIssue();
        $this->labelNames = $this->issue->labels->pluck('name')->all();

        Flux::toast(variant: 'success', text: __('Labels updated.'));
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
        return $this->team()->projects()->orderBy('name')->get();
    }

    protected function fillFromIssue(): void
    {
        $this->assigneeIds = $this->issue->assignees->pluck('id')->all();
        $this->labelNames = $this->issue->labels->pluck('name')->all();
        $this->title = $this->issue->title;
        $this->description = $this->issue->body ?? '';
        $this->projectId = $this->issue->project_id ?? '';
    }

    protected function refreshIssue(): void
    {
        $this->issue = $this->issue->fresh([
            'labels',
            'assignees',
            'comments.user',
            'connectedSource',
            'connection',
            'project',
        ]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div>
            <a href="{{ route('issues.index') }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to tasks') }}</a>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <flux:heading size="xl">{{ $issue->title }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">
                    {{ $issue->project->name ?? __('No project') }}
                    · {{ $issue->connectedSource->name ?? __('Local task') }}
                    @if ($issue->number)
                        · #{{ $issue->number }}
                    @endif
                    @if ($issue->external_url)
                        · <a href="{{ $issue->external_url }}" class="underline" target="_blank" rel="noreferrer">{{ __('Open source') }}</a>
                    @endif
                </flux:text>
            </div>

            <div class="flex items-center gap-2">
                <flux:badge :color="$issue->status === \App\Enums\IssueStatus::Open ? 'lime' : 'zinc'" data-test="issue-status">
                    {{ $issue->status->label() }}
                </flux:badge>
                @if ($issue->isLinkedToSource())
                    <flux:button
                        variant="ghost"
                        icon="arrow-path"
                        wire:click="refreshFromRemote"
                        wire:loading.attr="disabled"
                        wire:target="refreshFromRemote"
                        data-test="refresh-issue"
                    >
                        {{ __('Refresh') }}
                    </flux:button>
                @endif
                @if ($this->canUpdate)
                    <flux:button variant="filled" wire:click="toggleStatus" data-test="toggle-issue-status">
                        {{ $issue->status === \App\Enums\IssueStatus::Open ? __('Close') : __('Reopen') }}
                    </flux:button>
                @endif
                @if ($this->canDelete)
                    <flux:modal.trigger name="delete-task">
                        <flux:button variant="ghost" data-test="delete-task">{{ __('Delete') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </div>
        </div>

        @if ($this->canUpdate)
            <form wire:submit="saveDetails" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:input wire:model="title" :label="__('Title')" data-test="edit-task-title" />
                <flux:textarea wire:model="description" :label="__('Description')" rows="6" data-test="edit-task-description" />
                <flux:select wire:model="projectId" :label="__('Project')" data-test="edit-task-project">
                    <flux:select.option value="">{{ __('No project') }}</flux:select.option>
                    @foreach ($this->projects as $project)
                        <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button variant="primary" type="submit" data-test="save-task">{{ __('Save task') }}</flux:button>
            </form>
        @endif

        @if ($this->canDelete)
            <flux:modal name="delete-task" class="max-w-lg">
                <form wire:submit="delete" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete this task?') }}</flux:heading>
                        <flux:subheading>
                            @if ($issue->isLinkedToSource())
                                {{ __('This removes the task from this app. The GitHub issue stays where it is.') }}
                            @else
                                {{ __('This removes the task from this app.') }}
                            @endif
                        </flux:subheading>
                    </div>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit" data-test="delete-task-confirm">{{ __('Delete') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @if ($issue->body_html)
            <x-issue-html
                :content="$issue->body_html"
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
            />
        @elseif ($issue->body)
            <x-markdown
                :content="$issue->body"
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
            />
        @else
            <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">
                {{ __('No description.') }}
            </div>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Labels') }}</flux:heading>
                @if ($this->canUpdate)
                    <form wire:submit="saveLabels" class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->labels as $label)
                                <label class="flex items-center gap-2 text-sm" wire:key="label-{{ $label->id }}">
                                    <input type="checkbox" value="{{ $label->name }}" wire:model="labelNames">
                                    <span>{{ $label->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <flux:button type="submit" size="sm" data-test="save-labels">{{ __('Save labels') }}</flux:button>
                    </form>
                @else
                    <div class="flex flex-wrap gap-1">
                        @forelse ($issue->labels as $label)
                            <flux:badge color="zinc">{{ $label->name }}</flux:badge>
                        @empty
                            <flux:text>{{ __('No labels.') }}</flux:text>
                        @endforelse
                    </div>
                @endif
            </div>

            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Assignees') }}</flux:heading>
                @if ($this->canAssign)
                    <form wire:submit="saveAssignees" class="space-y-3">
                        <div class="space-y-2">
                            @foreach ($this->members as $member)
                                <label class="flex items-center gap-2 text-sm" wire:key="assignee-{{ $member->id }}">
                                    <input type="checkbox" value="{{ $member->id }}" wire:model="assigneeIds" data-test="assignee-checkbox">
                                    <span>{{ $member->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <flux:button type="submit" size="sm" data-test="save-assignees">{{ __('Save assignees') }}</flux:button>
                    </form>
                @else
                    <flux:text>{{ $issue->assignees->pluck('name')->join(', ') ?: __('Unassigned') }}</flux:text>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Comments') }}</flux:heading>

            <div class="space-y-3">
                @forelse ($issue->comments as $comment)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="comment-{{ $comment->id }}" data-test="issue-comment">
                        <div class="mb-2 flex items-center justify-between gap-2 text-sm text-zinc-500">
                            <span>{{ $comment->author_name }}</span>
                            <span>{{ $comment->created_at?->diffForHumans() }}</span>
                        </div>
                        @if ($comment->body_html)
                            <x-issue-html :content="$comment->body_html" />
                        @else
                            <x-markdown
                                :content="$comment->body"
                                :allow-html="$comment->origin === \App\Enums\CommentOrigin::Remote"
                            />
                        @endif
                    </div>
                @empty
                    <flux:text>{{ __('No comments yet.') }}</flux:text>
                @endforelse
            </div>

            <form wire:submit="addComment" class="space-y-3">
                <flux:textarea wire:model="body" :label="__('Comment')" rows="4" data-test="issue-comment-body" />
                <flux:button variant="primary" type="submit" data-test="issue-comment-submit">
                    {{ __('Comment') }}
                </flux:button>
            </form>
        </div>
    </div>
