<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
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
        ?string $clientId = null,
    ): Project {
        $project->update([
            'name' => $name,
            'description' => filled($description) ? $description : null,
            'status' => $status,
            'client_id' => $this->clientId($project, $clientId),
        ]);

        if ($project->wasChanged('client_id')) {
            $project->issues()->update([
                'client_id' => $project->client_id,
            ]);
        }

        Log::info('Project updated', [
            'project_id' => $project->id,
            'team_id' => $project->team_id,
        ]);

        return $project->fresh(['connectedSource']) ?? $project;
    }

    protected function clientId(Project $project, ?string $clientId): ?string
    {
        if (blank($clientId)) {
            return null;
        }

        $client = $project->team->clients()->whereKey($clientId)->first();

        if ($client === null) {
            throw ValidationException::withMessages([
                'clientId' => [__('Choose a client on this team.')],
            ]);
        }

        return $client->id;
    }
}
