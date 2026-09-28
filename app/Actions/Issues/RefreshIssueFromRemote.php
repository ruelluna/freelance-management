<?php

namespace App\Actions\Issues;

use App\Data\Integrations\RemoteComment;
use App\Data\Integrations\RemoteIssue;
use App\Enums\CommentOrigin;
use App\Models\ConnectedSource;
use App\Models\Issue;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshIssueFromRemote
{
    public function __construct(
        public IssueProviderFactory $providers,
        public SyncIssueFromRemote $sync,
    ) {}

    public function handle(Issue $issue): bool
    {
        try {
            $issue->loadMissing('connectedSource.connection', 'connection');

            if ($issue->connection_id === null || ($issue->number === null && blank($issue->external_id))) {
                return false;
            }

            $remote = $this->providers->make($issue->connection)->getIssue($issue);
            $this->syncRemote($issue->connectedSource, $remote);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Failed to refresh issue from remote', [
                'issue_id' => $issue->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function syncRemote(ConnectedSource $source, RemoteIssue $remote): Issue
    {
        $source->loadMissing('connection');

        $issue = $this->sync->handle($source, $remote);
        $comments = $this->providers->make($source->connection)->listComments($issue);

        $this->sync->syncComments($issue, $comments);
        $this->pruneMissingRemoteComments($issue, $comments);

        return $issue;
    }

    /**
     * @param  Collection<int, RemoteComment>  $comments
     */
    protected function pruneMissingRemoteComments(Issue $issue, Collection $comments): void
    {
        $remoteIds = $comments
            ->map(fn (RemoteComment $comment): string => $comment->externalId)
            ->filter()
            ->all();

        $query = $issue->comments()
            ->where('origin', CommentOrigin::Remote)
            ->whereNotNull('external_id');

        if ($remoteIds === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn('external_id', $remoteIds)->delete();
    }
}
