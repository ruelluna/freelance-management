<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Enums\Provider;
use App\Models\ConnectedSource;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateProject
{
    public function handle(Team $team, string $name, ?string $description, ?string $connectedSourceId): Project
    {
        $project = $team->projects()->create([
            'name' => $name,
            'description' => filled($description) ? $description : null,
            'status' => ProjectStatus::Open,
            'connected_source_id' => $this->githubSourceId($team, $connectedSourceId),
        ]);

        Log::info('Project created', [
            'project_id' => $project->id,
            'team_id' => $team->id,
        ]);

        return $project;
    }

    protected function githubSourceId(Team $team, ?string $connectedSourceId): ?string
    {
        if (blank($connectedSourceId)) {
            return null;
        }

        $source = ConnectedSource::query()
            ->whereKey($connectedSourceId)
            ->where('team_id', $team->id)
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
