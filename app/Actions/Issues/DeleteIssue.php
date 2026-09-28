<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use Illuminate\Support\Facades\Log;

class DeleteIssue
{
    public function handle(Issue $issue): void
    {
        Log::info('Task deleted', [
            'issue_id' => $issue->id,
            'team_id' => $issue->team_id,
            'linked' => $issue->isLinkedToSource(),
        ]);

        $issue->delete();
    }
}
