<?php

namespace App\Jobs;

use App\Enums\CommentAudience;
use App\Enums\Provider;
use App\Models\IssueComment;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushCommentToSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [1, 5, 10];

    public function __construct(public string $commentId) {}

    public function handle(IssueProviderFactory $providers): void
    {
        $comment = IssueComment::query()
            ->with(['issue.connectedSource', 'issue.connection'])
            ->findOrFail($this->commentId);

        if ($comment->audience === CommentAudience::Client || $comment->external_id || $comment->issue->connection_id === null) {
            return;
        }

        if ($comment->issue->connection?->provider === Provider::Superhuman) {
            return;
        }

        $remote = $providers->make($comment->issue->connection)
            ->createComment($comment->issue, $comment->body);

        $existing = IssueComment::query()
            ->where('issue_id', $comment->issue_id)
            ->where('external_id', $remote->externalId)
            ->whereKeyNot($comment->id)
            ->first();

        if ($existing) {
            $comment->delete();

            return;
        }

        $comment->update([
            'external_id' => $remote->externalId,
            'synced_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to push comment to source', [
            'comment_id' => $this->commentId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
