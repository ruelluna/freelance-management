<?php

namespace App\Actions\Issues;

use App\Enums\CommentOrigin;
use App\Jobs\PushCommentToSource;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;

class AddIssueComment
{
    public function handle(Issue $issue, User $user, string $body): IssueComment
    {
        $comment = $issue->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'author_name' => $user->name,
            'origin' => CommentOrigin::Local,
        ]);

        if ($issue->connection_id !== null) {
            PushCommentToSource::dispatch($comment->id);
        }

        return $comment;
    }
}
