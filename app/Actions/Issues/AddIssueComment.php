<?php

namespace App\Actions\Issues;

use App\Enums\CommentAudience;
use App\Enums\CommentOrigin;
use App\Jobs\PushCommentToSource;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AddIssueComment
{
    public function handle(Issue $issue, User $user, string $body, CommentAudience $audience, ?IssueComment $parent = null): IssueComment
    {
        $parent = $this->replyParent($issue, $parent);
        $audience = $this->audienceFor($issue, $user, $audience, $parent);

        $comment = $issue->comments()->create([
            'parent_id' => $parent?->id,
            'user_id' => $user->id,
            'body' => $body,
            'author_name' => $user->name,
            'origin' => CommentOrigin::Local,
            'audience' => $audience,
        ]);

        if ($audience === CommentAudience::Internal && $issue->connection_id !== null) {
            PushCommentToSource::dispatch($comment->id);
        }

        return $comment;
    }

    protected function replyParent(Issue $issue, ?IssueComment $parent): ?IssueComment
    {
        if ($parent === null) {
            return null;
        }

        abort_unless($parent->issue_id === $issue->id, 404);

        return $parent;
    }

    /**
     * Client users always write on the client thread. Admins and employees always write team notes.
     * A team note cannot sit under a private client comment, and a client reply cannot sit under a team note.
     */
    protected function audienceFor(Issue $issue, User $user, CommentAudience $requested, ?IssueComment $parent): CommentAudience
    {
        $issue->loadMissing('team');

        if ($user->isTeamClient($issue->team)) {
            $this->guardClientReply($parent);

            return CommentAudience::Client;
        }

        if (! $user->ownsTeam($issue->team)) {
            $this->guardTeamNote($parent);

            return CommentAudience::Internal;
        }

        if ($parent?->audience === CommentAudience::Internal) {
            if ($requested === CommentAudience::Client) {
                throw ValidationException::withMessages([
                    'body' => __('The client cannot see a reply under a team note.'),
                ]);
            }

            return CommentAudience::Internal;
        }

        if ($requested === CommentAudience::Internal) {
            $this->guardTeamNote($parent);
        }

        return $requested;
    }

    protected function guardClientReply(?IssueComment $parent): void
    {
        if ($parent !== null && $parent->audience !== CommentAudience::Client) {
            throw ValidationException::withMessages([
                'body' => __('The client cannot see a reply under a team note.'),
            ]);
        }
    }

    protected function guardTeamNote(?IssueComment $parent): void
    {
        if ($parent !== null && $parent->audience === CommentAudience::Client && ! $parent->isSharedWithTeam()) {
            throw ValidationException::withMessages([
                'body' => __('Share this comment with the team before leaving a team note.'),
            ]);
        }
    }
}
