<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

class DeleteProject
{
    public function handle(Project $project): void
    {
        Log::info('Project deleted', [
            'project_id' => $project->id,
            'team_id' => $project->team_id,
        ]);

        $project->delete();
    }
}
