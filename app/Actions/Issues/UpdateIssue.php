<?php

namespace App\Actions\Issues;

use App\Actions\EditorImages\AttachEditorImages;
use App\Data\Integrations\IssueUpdate as RemoteIssueUpdate;
use App\Enums\IssueStatus;
use App\Enums\Provider;
use App\Models\Issue;
use App\Models\Label;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Facades\Log;

class UpdateIssue
{
    public function __construct(
        private IssueProviderFactory $providers,
        private AttachEditorImages $images,
    ) {}

    public function handle(Issue $issue, RemoteIssueUpdate $update, ?User $actor = null): Issue
    {
        if ($update->body !== null && $actor !== null) {
            $update = new RemoteIssueUpdate(
                status: $update->status,
                labelNames: $update->labelNames,
                assigneeLogins: $update->assigneeLogins,
                title: $update->title,
                body: $this->images->handle($actor, $issue, $update->body),
            );
        }

        $attributes = [];

        if ($update->status !== null) {
            $attributes['status'] = IssueStatus::from($update->status);
        }

        if ($update->title !== null) {
            $attributes['title'] = $update->title;
        }

        if ($update->body !== null) {
            $attributes['body'] = $update->body;
            $attributes['body_html'] = null;
        }

        if ($attributes !== []) {
            $issue->update($attributes);
        }

        if ($update->labelNames !== null) {
            $labelIds = collect($update->labelNames)
                ->map(fn (string $name): string => Label::query()->firstOrCreate(
                    ['team_id' => $issue->team_id, 'name' => $name],
                    ['color' => 'ededed'],
                )->id)
                ->all();

            $issue->labels()->sync($labelIds);
        }

        if ($issue->connection_id === null) {
            return $issue->fresh(['labels', 'assignees']) ?? $issue;
        }

        try {
            $this->providers->make($issue->connection)->updateIssue($issue, $update);
        } catch (\Throwable $exception) {
            Log::warning('Failed to push issue update to source', [
                'issue_id' => $issue->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $issue->fresh(['labels', 'assignees']) ?? $issue;
    }

    /**
     * @return array<int, string>
     */
    public function githubLoginsFor(Issue $issue): array
    {
        return UserIdentity::query()
            ->where('provider', Provider::Github)
            ->whereIn('user_id', $issue->assignees()->pluck('users.id'))
            ->pluck('external_id')
            ->all();
    }
}
