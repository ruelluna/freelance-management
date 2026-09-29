<?php

namespace App\Actions\Projects;

use App\Actions\EditorImages\AttachEditorImages;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateProject
{
    public function __construct(private AttachEditorImages $images) {}

    public function handle(
        Team $team,
        string $name,
        ?string $description,
        ?string $clientId = null,
        ?User $actor = null,
    ): Project {
        $project = $team->projects()->create([
            'name' => $name,
            'description' => filled($description) ? $description : null,
            'status' => ProjectStatus::Open,
            'client_id' => $this->clientId($team, $clientId),
        ]);

        if ($actor !== null) {
            $description = $this->images->handle($actor, $project, $description);
            $stored = filled($description) ? $description : null;

            if ($project->description !== $stored) {
                $project->update(['description' => $stored]);
            }
        }

        Log::info('Project created', [
            'project_id' => $project->id,
            'team_id' => $team->id,
        ]);

        return $project;
    }

    protected function clientId(Team $team, ?string $clientId): ?string
    {
        if (blank($clientId)) {
            return null;
        }

        $client = $team->clients()->whereKey($clientId)->first();

        if ($client === null) {
            throw ValidationException::withMessages([
                'clientId' => [__('Choose a client on this team.')],
            ]);
        }

        return $client->id;
    }
}
