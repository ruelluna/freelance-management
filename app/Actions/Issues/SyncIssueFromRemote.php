<?php

namespace App\Actions\Issues;

use App\Data\Integrations\RemoteComment;
use App\Data\Integrations\RemoteIssue;
use App\Enums\CommentAudience;
use App\Enums\CommentOrigin;
use App\Enums\Provider;
use App\Models\ConnectedSource;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Label;
use App\Models\UserIdentity;
use App\Services\Integrations\GithubIssueProvider;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncIssueFromRemote
{
    public function __construct(
        public IssueProviderFactory $providers,
        public CacheIssueMediaFromHtml $cacheMedia,
    ) {}

    public function handle(ConnectedSource $source, RemoteIssue $remote): Issue
    {
        return DB::transaction(function () use ($source, $remote): Issue {
            $source->loadMissing('connection');

            $existing = Issue::query()
                ->where('connected_source_id', $source->id)
                ->where('external_id', $remote->externalId)
                ->first();

            $skipIssueHtml = $existing !== null
                && $existing->body === $remote->body
                && filled($existing->body_html)
                && ! $this->cacheMedia->referencesRemoteMedia($existing->body_html);

            $attributes = [
                'team_id' => $source->team_id,
                'connection_id' => $source->connection_id,
                'number' => $remote->number,
                'title' => $remote->title,
                'body' => $remote->body,
                'status' => $remote->status,
                'external_url' => $remote->externalUrl,
                'external_updated_at' => $remote->externalUpdatedAt,
                'last_synced_at' => now(),
            ];

            if ($existing === null && $source->connection->user_id !== null && $source->connection->provider !== Provider::Github) {
                $attributes['created_by'] = $source->connection->user_id;
            }

            if ($source->connection->provider === Provider::Superhuman && $source->connection->project_id !== null) {
                $attributes['project_id'] = $source->connection->project_id;
            }

            $issue = Issue::query()->updateOrCreate(
                [
                    'connected_source_id' => $source->id,
                    'external_id' => $remote->externalId,
                ],
                $attributes,
            );

            if (! $source->connection->skipsRemoteAssignment()) {
                $this->syncLabels($issue, $remote->labels);
                $this->syncAssignees($issue, $remote->assigneeLogins);
            }

            if (! $skipIssueHtml) {
                $this->syncIssueBodyHtml($source, $issue, $remote->body);
            }

            return $issue->fresh(['labels', 'assignees']) ?? $issue;
        });
    }

    /**
     * @param  Collection<int, RemoteComment>  $comments
     */
    public function syncComments(Issue $issue, Collection $comments): void
    {
        $issue->loadMissing('connectedSource.connection');
        $source = $issue->connectedSource;

        foreach ($comments as $comment) {
            $existing = IssueComment::query()
                ->where('issue_id', $issue->id)
                ->where('external_id', $comment->externalId)
                ->first();

            $skipCommentHtml = $existing !== null
                && $existing->body === $comment->body
                && filled($existing->body_html)
                && ! $this->cacheMedia->referencesRemoteMedia($existing->body_html);

            $origin = $existing?->origin === CommentOrigin::Local
                ? CommentOrigin::Local
                : CommentOrigin::Remote;

            $attributes = [
                'body' => $comment->body,
                'author_name' => $comment->authorName,
                'origin' => $origin,
                'synced_at' => now(),
            ];

            if ($existing === null) {
                $attributes['audience'] = CommentAudience::Internal;
            }

            $issueComment = IssueComment::query()->updateOrCreate(
                [
                    'issue_id' => $issue->id,
                    'external_id' => $comment->externalId,
                ],
                $attributes,
            );

            if (! $skipCommentHtml) {
                $this->syncCommentBodyHtml($source, $issue, $issueComment, (int) $comment->externalId);
            }
        }
    }

    protected function syncIssueBodyHtml(ConnectedSource $source, Issue $issue, ?string $markdownBody): void
    {
        if ($source->connection->provider !== Provider::Github || $issue->number === null) {
            return;
        }

        $provider = $this->providers->make($source->connection);

        if (! $provider instanceof GithubIssueProvider) {
            return;
        }

        $html = $provider->fetchIssueBodyHtml($source, $issue->number);
        $bodyHtml = $this->cacheMedia->forIssue($issue, $html, $markdownBody);

        if ($bodyHtml !== null) {
            $issue->update(['body_html' => $bodyHtml]);
        }
    }

    protected function syncCommentBodyHtml(
        ConnectedSource $source,
        Issue $issue,
        IssueComment $comment,
        int $commentId,
    ): void {
        if ($source->connection->provider !== Provider::Github || $commentId <= 0) {
            return;
        }

        $provider = $this->providers->make($source->connection);

        if (! $provider instanceof GithubIssueProvider) {
            return;
        }

        $html = $provider->fetchCommentBodyHtml($source, $commentId);
        $bodyHtml = $this->cacheMedia->forComment($issue, $comment, $html);

        if ($bodyHtml !== null) {
            $comment->update(['body_html' => $bodyHtml]);
        }
    }

    /**
     * @param  array<int, array{name: string, color: string}>  $labels
     */
    protected function syncLabels(Issue $issue, array $labels): void
    {
        $labelIds = [];

        foreach ($labels as $remoteLabel) {
            $label = Label::query()->firstOrCreate(
                [
                    'team_id' => $issue->team_id,
                    'name' => $remoteLabel['name'],
                ],
                [
                    'color' => $remoteLabel['color'],
                ],
            );

            if ($label->color !== $remoteLabel['color']) {
                $label->update(['color' => $remoteLabel['color']]);
            }

            $labelIds[] = $label->id;
        }

        $issue->labels()->sync($labelIds);
    }

    /**
     * @param  array<int, string>  $logins
     */
    protected function syncAssignees(Issue $issue, array $logins): void
    {
        $matchedUserIds = UserIdentity::query()
            ->where('provider', Provider::Github)
            ->whereIn('external_id', $logins)
            ->pluck('user_id');

        $localOnlyIds = $issue->assignees()
            ->whereDoesntHave('identities', function ($query): void {
                $query->where('provider', Provider::Github);
            })
            ->pluck('users.id');

        $issue->assignees()->sync($matchedUserIds->merge($localOnlyIds)->unique()->all());
    }
}
