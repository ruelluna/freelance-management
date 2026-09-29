<?php

namespace App\Actions\Issues;

use App\Actions\EditorImages\AttachEditorImages;
use App\Data\CreatedIssue;
use App\Enums\IssueStatus;
use App\Enums\Provider;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateIssue
{
    public function __construct(
        private IssueProviderFactory $providers,
        private ResolveTaskAssignees $assignees,
        private AttachEditorImages $images,
    ) {}

    /**
     * @param  array<int, int|string>  $assigneeIds
     * @param  array<int, string>  $labelNames
     */
    public function handle(
        Team $team,
        User $creator,
        Project $project,
        string $title,
        ?string $description,
        array $assigneeIds,
        array $labelNames,
        bool $publishToGithub,
    ): CreatedIssue {
        if ($project->team_id !== $team->id) {
            throw ValidationException::withMessages([
                'projectId' => __('Choose a project on this team.'),
            ]);
        }

        $staffIds = $this->assignees->handle($team, $assigneeIds, $creator);

        if ($creator->isTeamClient($team)) {
            $labelNames = [];
        }

        $issue = DB::transaction(function () use ($team, $creator, $project, $title, $description, $staffIds, $labelNames): Issue {
            $issue = $team->issues()->create([
                'project_id' => $project->id,
                'client_id' => $project->client_id,
                'created_by' => $creator->id,
                'title' => $title,
                'body' => filled($description) ? $description : null,
                'status' => IssueStatus::Open,
            ]);

            $description = $this->images->handle($creator, $issue, $description);
            $body = filled($description) ? $description : null;

            if ($issue->body !== $body) {
                $issue->update(['body' => $body]);
            }

            $issue->assignees()->sync($staffIds);

            $labelIds = collect($labelNames)
                ->filter(fn (string $name): bool => $name !== '')
                ->map(fn (string $name): string => Label::query()->firstOrCreate(
                    ['team_id' => $team->id, 'name' => $name],
                    ['color' => 'ededed'],
                )->id)
                ->all();

            $issue->labels()->sync($labelIds);

            return $issue;
        });

        $publishError = null;

        if ($publishToGithub && $project->connected_source_id !== null) {
            $publishError = $this->publish($issue, $project);
        }

        Log::info('Task created', [
            'issue_id' => $issue->id,
            'team_id' => $team->id,
            'project_id' => $project->id,
            'published' => $publishError === null && ($issue->fresh()?->isLinkedToSource() ?? false),
        ]);

        return new CreatedIssue(
            $issue->fresh(['labels', 'assignees', 'project', 'connectedSource']) ?? $issue,
            $publishError,
        );
    }

    protected function publish(Issue $issue, Project $project): ?string
    {
        $project->loadMissing('connectedSource.connection');
        $source = $project->connectedSource;

        if ($source === null || $source->connection->provider !== Provider::Github) {
            return null;
        }

        try {
            $logins = UserIdentity::query()
                ->where('provider', Provider::Github)
                ->whereIn('user_id', $issue->assignees()->pluck('users.id'))
                ->pluck('external_id')
                ->all();

            $remote = $this->providers->make($source->connection)->createIssue(
                $source,
                $issue->title,
                $issue->body,
                $issue->labels()->pluck('name')->all(),
                $logins,
            );

            $issue->update([
                'connection_id' => $source->connection_id,
                'connected_source_id' => $source->id,
                'external_id' => $remote->externalId,
                'number' => $remote->number,
                'external_url' => $remote->externalUrl,
                'external_updated_at' => $remote->externalUpdatedAt,
                'last_synced_at' => now(),
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('Failed to create GitHub issue for task', [
                'issue_id' => $issue->id,
                'error' => $exception->getMessage(),
            ]);

            return __('Could not create the GitHub issue. The task was saved locally.');
        }
    }
}
