<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteProject
{
    public function handle(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $project->issues()->update([
                'connection_id' => null,
                'connected_source_id' => null,
            ]);

            Log::info('Project deleted', [
                'project_id' => $project->id,
                'team_id' => $project->team_id,
            ]);

            $project->delete();
        });
    }
}
