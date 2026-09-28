<?php

namespace App\Actions\Issues;

use App\Models\Client;
use App\Models\Issue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssignIssueClient
{
    public function handle(Issue $issue, ?Client $client): Issue
    {
        if ($client !== null && $client->team_id !== $issue->team_id) {
            throw ValidationException::withMessages([
                'clientId' => __('Choose a client on this team.'),
            ]);
        }

        $issue->loadMissing('project');

        $attributes = [
            'client_id' => $client?->id,
        ];

        if ($issue->project !== null && $issue->project->client_id !== $client?->id) {
            $attributes['project_id'] = null;
        }

        $issue->update($attributes);

        Log::info('Task client assigned', [
            'issue_id' => $issue->id,
            'team_id' => $issue->team_id,
            'client_id' => $client?->id,
            'project_id' => $issue->project_id,
        ]);

        return $issue;
    }
}
