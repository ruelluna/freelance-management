@props([
    'comment',
    'isOwner' => false,
    'isClient' => false,
])

<div class="space-y-3" wire:key="comment-{{ $comment->id }}">
    <x-card>
        <div data-test="issue-comment">
            <div class="mb-2 flex items-center justify-between gap-2 text-sm text-zinc-500">
                <span>{{ $comment->author_name }}</span>
                <span>{{ $comment->created_at?->diffForHumans() }}</span>
            </div>
            @if ($isOwner)
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    @if ($comment->audience === \App\Enums\CommentAudience::Client)
                        @if ($comment->isSharedWithTeam())
                            <x-badge color="green" light data-test="comment-shared" :text="__('Shared with team')" />
                            <x-button outline sm wire:click="unshareComment('{{ $comment->id }}')" data-test="unshare-comment" :text="__('Make private')" />
                        @else
                            <x-badge color="gray" light data-test="comment-private" :text="__('Private')" />
                            <x-button outline sm wire:click="shareComment('{{ $comment->id }}')" data-test="share-comment" :text="__('Share with team')" />
                        @endif
                    @else
                        <x-badge color="gray" light data-test="comment-team-note" :text="__('Team note')" />
                    @endif
                </div>
            @elseif (! $isClient && $comment->audience === \App\Enums\CommentAudience::Client)
                <div class="mb-2">
                    <x-badge color="gray" light data-test="comment-from-client" :text="__('From the client')" />
                </div>
            @endif
            @if ($comment->body_html)
                <x-issue-html :content="$comment->body_html" />
            @else
                <x-markdown
                    :content="$comment->body"
                    :allow-html="$comment->origin === \App\Enums\CommentOrigin::Remote"
                />
            @endif
            <div class="mt-3">
                <x-button outline sm wire:click="startReply('{{ $comment->id }}')" data-test="start-reply" :text="__('Reply')" />
            </div>
        </div>
    </x-card>

    @if ($comment->replies->isNotEmpty())
        <div class="ms-6 space-y-3 border-s border-zinc-200 ps-4 dark:border-zinc-700" data-test="comment-replies">
            @foreach ($comment->replies as $reply)
                <x-issues.comment :comment="$reply" :is-owner="$isOwner" :is-client="$isClient" />
            @endforeach
        </div>
    @endif
</div>
