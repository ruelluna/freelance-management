<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssignIssueProject
{
    public function handle(Issue $issue, ?Project $project): Issue
    {
        if ($project !== null && $project->team_id !== $issue->team_id) {
            throw ValidationException::withMessages([
                'projectId' => __('Choose a project on this team.'),
            ]);
        }

        $issue->update([
            'project_id' => $project?->id,
            'client_id' => $project?->client_id,
        ]);

        Log::info('Task project assigned', [
            'issue_id' => $issue->id,
            'team_id' => $issue->team_id,
            'project_id' => $project?->id,
            'client_id' => $project?->client_id,
        ]);

        return $issue;
    }
}
