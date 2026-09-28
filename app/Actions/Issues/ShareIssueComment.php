<?php

namespace App\Actions\Issues;

use App\Enums\CommentAudience;
use App\Models\IssueComment;
use App\Models\User;

class ShareIssueComment
{
    public function handle(IssueComment $comment, User $user): void
    {
        $comment->loadMissing('issue.team');

        abort_unless($user->ownsTeam($comment->issue->team), 403);
        abort_unless($comment->audience === CommentAudience::Client, 404);

        $comment->update(['shared_at' => now()]);
    }
}
