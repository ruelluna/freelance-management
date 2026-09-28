<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Enums\Provider;
use App\Models\ConnectedSource;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProject
{
    public function handle(
        Project $project,
        string $name,
        ?string $description,
        ProjectStatus $status,
        ?string $connectedSourceId,
    ): Project {
        $project->update([
            'name' => $name,
            'description' => filled($description) ? $description : null,
            'status' => $status,
            'connected_source_id' => $this->githubSourceId($project, $connectedSourceId),
        ]);

        Log::info('Project updated', [
            'project_id' => $project->id,
            'team_id' => $project->team_id,
        ]);

        return $project->fresh(['connectedSource']) ?? $project;
    }

    protected function githubSourceId(Project $project, ?string $connectedSourceId): ?string
    {
        if (blank($connectedSourceId)) {
            return null;
        }

        $source = ConnectedSource::query()
            ->whereKey($connectedSourceId)
            ->where('team_id', $project->team_id)
            ->whereHas('connection', fn ($query) => $query->where('provider', Provider::Github))
            ->first();

        if ($source === null) {
            throw ValidationException::withMessages([
                'connectedSourceId' => __('Choose a GitHub repository on this team.'),
            ]);
        }

        return $source->id;
    }
}
