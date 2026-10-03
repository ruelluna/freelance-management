<?php

use App\Actions\Issues\AddIssueComment;
use App\Actions\Issues\CacheIssueMediaFromHtml;
use App\Actions\Issues\RefreshIssueFromRemote;
use App\Actions\Issues\ShareIssueComment;
use App\Actions\Issues\UnshareIssueComment;
use App\Enums\CommentAudience;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Task')] class extends Component {
    use Interactions;

    public Issue $issue;

    public string $body = '';

    public ?string $replyToId = null;

    public function mount(Issue $issue, RefreshIssueFromRemote $refresh, CacheIssueMediaFromHtml $cacheMedia): void
    {
        abort_unless($issue->team_id === $this->team()->id, 404);

        Gate::authorize('view', $issue);

        $this->issue = $issue->load($this->issueRelations());

        if ($this->issue->isLinkedToSource() && ! Auth::user()->isScopedTeamUser($this->team())) {
            $stale = $this->issue->last_synced_at === null
                || $this->issue->last_synced_at->lte(now()->subSeconds(60));

            if ($stale) {
                if (! $refresh->handle($this->issue)) {
                    $this->toast()->warning(__('Could not refresh from :source. Showing the last saved copy.', [
                        'source' => $this->issue->connection->provider->label(),
                    ]))->send();
                }

                $this->refreshIssue();
            }
        }

        $this->cacheDescriptionMedia($cacheMedia);
    }

    public function refreshFromRemote(RefreshIssueFromRemote $refresh): void
    {
        Gate::authorize('view', $this->issue);

        if (! $this->issue->isLinkedToSource()) {
            return;
        }

        $ok = $refresh->handle($this->issue);

        $this->refreshIssue();

        if ($ok) {
            $this->toast()->success(__('Issue updated from :source.', [
                'source' => $this->issue->connection->provider->label(),
            ]))->send();

            return;
        }

        $this->toast()->warning(__('Could not refresh from :source. Showing the last saved copy.', [
            'source' => $this->issue->connection->provider->label(),
        ]))->send();
    }

    public function startReply(string $commentId): void
    {
        Gate::authorize('comment', $this->issue);

        $comment = $this->visibleComment($commentId);

        $this->replyToId = $comment->id;
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    public function addComment(AddIssueComment $action, ?string $audience = null): void
    {
        Gate::authorize('comment', $this->issue);

        $this->validate([
            'body' => ['required', 'string', 'min:1', 'max:65535'],
        ]);

        $parent = filled($this->replyToId) ? $this->visibleComment($this->replyToId) : null;

        $comment = $action->handle(
            $this->issue,
            Auth::user(),
            $this->body,
            $this->resolveAudience($audience),
            $parent,
        );

        $this->reset('body', 'replyToId');
        $this->refreshIssue();

        if ($comment->audience === CommentAudience::Client && Auth::user()->ownsTeam($this->team())) {
            $this->toast()->success(__('Reply sent to the client.'))->send();

            return;
        }

        if ($comment->audience === CommentAudience::Internal && $this->issue->isLinkedToSource()) {
            $this->toast()->success(__('Comment sent to :source.', [
                'source' => $this->issue->connection->provider->label(),
            ]))->send();

            return;
        }

        $this->toast()->success(__('Comment added.'))->send();
    }

    public function shareComment(string $commentId, ShareIssueComment $action): void
    {
        abort_unless(Auth::user()->ownsTeam($this->team()), 403);

        $comment = $this->issue->comments()
            ->visibleTo(Auth::user(), $this->team())
            ->findOrFail($commentId);

        $action->handle($comment, Auth::user());
        $this->refreshIssue();

        $this->toast()->success(__('Shared with the team.'))->send();
    }

    public function unshareComment(string $commentId, UnshareIssueComment $action): void
    {
        abort_unless(Auth::user()->ownsTeam($this->team()), 403);

        $comment = $this->issue->comments()
            ->visibleTo(Auth::user(), $this->team())
            ->findOrFail($commentId);

        $action->handle($comment, Auth::user());
        $this->refreshIssue();

        $this->toast()->success(__('Comment is private again.'))->send();
    }

    #[Computed]
    public function canEdit(): bool
    {
        $user = Auth::user();

        return $user->can('update', $this->issue) || $user->can('assign', $this->issue);
    }

    #[Computed]
    public function isScopedUser(): bool
    {
        return Auth::user()->isScopedTeamUser($this->team());
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->ownsTeam($this->team());
    }

    #[Computed]
    public function isClient(): bool
    {
        return Auth::user()->isTeamClient($this->team());
    }

    /**
     * @return Collection<int, IssueComment>
     */
    #[Computed]
    public function commentThreads(): Collection
    {
        $comments = $this->issue->comments;
        $byParent = $comments->groupBy(fn (IssueComment $comment): string => $comment->parent_id ?? 'root');

        $nest = function (IssueComment $comment) use (&$nest, $byParent): IssueComment {
            $replies = $byParent
                ->get($comment->id, collect())
                ->map(fn (IssueComment $reply): IssueComment => $nest($reply))
                ->values();

            $comment->setRelation('replies', $replies);

            return $comment;
        };

        return $byParent
            ->get('root', collect())
            ->map(fn (IssueComment $comment): IssueComment => $nest($comment))
            ->values();
    }

    #[Computed]
    public function replyTarget(): ?IssueComment
    {
        if (blank($this->replyToId)) {
            return null;
        }

        return $this->issue->comments->firstWhere('id', $this->replyToId);
    }

    #[Computed]
    public function canReplyToClient(): bool
    {
        if (! Auth::user()->ownsTeam($this->team())) {
            return false;
        }

        $parent = $this->replyTarget;

        return $parent === null || $parent->audience === CommentAudience::Client;
    }

    #[Computed]
    public function canLeaveTeamNote(): bool
    {
        if (Auth::user()->isTeamClient($this->team())) {
            return false;
        }

        $parent = $this->replyTarget;

        if ($parent === null || $parent->audience === CommentAudience::Internal) {
            return true;
        }

        return $parent->isSharedWithTeam();
    }

    protected function refreshIssue(): void
    {
        $this->issue = $this->issue->fresh($this->issueRelations());
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function issueRelations(): array
    {
        $relations = [
            'assignees',
            'comments' => function (Relation $comments): void {
                $comments->visibleTo(Auth::user(), $this->team());
                $comments->with('user');
            },
            'project',
            'creator',
        ];

        if (! Auth::user()->isTeamClient($this->team())) {
            array_unshift($relations, 'labels');
            $relations[] = 'connectedSource';
            $relations[] = 'connection';
        }

        return $relations;
    }

    protected function resolveAudience(?string $audience): CommentAudience
    {
        $user = Auth::user();

        if ($user->isTeamClient($this->team())) {
            return CommentAudience::Client;
        }

        if (! $user->ownsTeam($this->team())) {
            return CommentAudience::Internal;
        }

        return CommentAudience::tryFrom($audience ?? '') ?? CommentAudience::Internal;
    }

    protected function visibleComment(string $commentId): IssueComment
    {
        return $this->issue->comments()
            ->visibleTo(Auth::user(), $this->team())
            ->findOrFail($commentId);
    }

    protected function cacheDescriptionMedia(CacheIssueMediaFromHtml $cacheMedia): void
    {
        $this->issue->loadMissing('connection', 'team');

        $html = $this->issue->body_html;

        if (blank($html) && filled($this->issue->body) && $cacheMedia->referencesRemoteMedia($this->issue->body)) {
            $html = \App\Support\Markdown::toHtml($this->issue->body);
        }

        if (! is_string($html) || ! $cacheMedia->referencesRemoteMedia($html)) {
            return;
        }

        $rewritten = $cacheMedia->forIssue($this->issue, $html, $this->issue->body);

        if (! is_string($rewritten) || $rewritten === $this->issue->body_html) {
            return;
        }

        $this->issue->update(['body_html' => $rewritten]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div>
            <a href="{{ route(auth()->user()->sectionRoute('issues.index')) }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to tasks') }}</a>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $issue->title }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">
                    {{ $issue->project->name ?? __('No project') }}
                    @unless (auth()->user()->isTeamClient(auth()->user()->currentTeam))
                        · {{ $issue->sourceLabel() }}
                        @if ($issue->connection_id === null && $issue->creator)
                            · {{ $issue->creator->name }}
                        @endif
                    @endunless
                    @if ($issue->number)
                        · #{{ $issue->number }}
                    @endif
                    @if ($issue->external_url && ! $this->isScopedUser)
                        · <a href="{{ $issue->external_url }}" class="underline" target="_blank" rel="noreferrer">{{ __('Open source') }}</a>
                    @endif
                </p>
            </div>

            <div class="flex items-center gap-2">
                <x-badge light data-test="issue-status" :color="$issue->status === \App\Enums\IssueStatus::Open ? 'green' : 'gray'" :text="$issue->status->label()" />
                @if ($issue->isLinkedToSource() && ! $this->isScopedUser)
                    <x-button
                        outline
                        icon="arrow-path"
                        wire:click="refreshFromRemote"
                        wire:loading.attr="disabled"
                        wire:target="refreshFromRemote"
                        data-test="refresh-issue"
                        :text="__('Refresh')"
                    />
                @endif
                @if ($this->canEdit)
                    <x-button
                        outline
                        icon="pencil-square"
                        :href="route(auth()->user()->sectionRoute('issues.edit'), $issue)"
                        wire:navigate
                        data-test="edit-task"
                        :text="__('Edit')"
                    />
                @endif
            </div>
        </div>

        @if ($issue->body_html)
            <x-issue-html
                new-tab
                :content="$issue->body_html"
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-dark-700 dark:bg-dark-800"
            />
        @elseif ($issue->body)
            <x-markdown
                new-tab
                :content="$issue->body"
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-dark-700 dark:bg-dark-800"
            />
        @else
            <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-500 dark:border-dark-700 dark:bg-dark-800">
                {{ __('No description.') }}
            </div>
        @endif

        <div @class(['grid gap-6', 'md:grid-cols-2' => ! $this->isClient])>
            @unless ($this->isClient)
                <div class="space-y-3">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Labels') }}</h2>
                    <div class="flex flex-wrap gap-1">
                        @forelse ($issue->labels as $label)
                            <x-badge color="gray" light :text="$label->name" />
                        @empty
                            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No labels.') }}</p>
                        @endforelse
                    </div>
                </div>
            @endunless

            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Assignees') }}</h2>
                <p class="text-sm text-gray-500 dark:text-dark-300" data-test="issue-assignees">{{ $issue->assignees->pluck('name')->join(', ') ?: __('Unassigned') }}</p>
            </div>
        </div>

        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Comments') }}</h2>

            <div class="space-y-3">
                @forelse ($this->commentThreads as $comment)
                    <div data-test="comment-thread">
                        <x-issues.comment :comment="$comment" :is-owner="$this->isOwner" :is-client="$this->isClient" />
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No comments yet.') }}</p>
                @endforelse
            </div>

            <div class="space-y-3">
                @if ($this->replyTarget)
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-gray-500 dark:text-dark-300" data-test="replying-to">
                            {{ __('Replying to :name', ['name' => $this->replyTarget->author_name]) }}
                        </p>
                        <x-button outline sm wire:click="cancelReply" data-test="cancel-reply" :text="__('Cancel')" />
                    </div>
                @endif
                <livewire:markdown-editor wire:model="body" :label="$this->replyTarget ? __('Reply') : __('Comment')" min-height="8rem" test-id="issue-comment-body" />
                @if ($this->isClient)
                    <x-button wire:click="addComment" data-test="issue-comment-submit" :text="__('Comment')" />
                @elseif ($this->canReplyToClient && $this->canLeaveTeamNote)
                    <p class="text-sm text-gray-500 dark:text-dark-300">
                        {{ __('A reply to the client stays private until you share it. A team note is visible to your employees and hidden from the client.') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <x-button wire:click="addComment('client')" data-test="reply-to-client" :text="__('Reply to client')" />
                        <x-button outline wire:click="addComment('internal')" data-test="note-for-team" :text="__('Note for team')" />
                    </div>
                @elseif ($this->canReplyToClient)
                    <p class="text-sm text-gray-500 dark:text-dark-300" data-test="share-before-team-note">
                        {{ __('Share this comment with the team before leaving a team note.') }}
                    </p>
                    <x-button wire:click="addComment('client')" data-test="reply-to-client" :text="__('Reply to client')" />
                @else
                    <p class="text-sm text-gray-500 dark:text-dark-300">
                        {{ __('This note is visible to the team and hidden from the client.') }}
                    </p>
                    <x-button wire:click="addComment('internal')" data-test="note-for-team" :text="__('Note for team')" />
                @endif
            </div>
        </div>
    </div>
