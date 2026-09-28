<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Issues\SyncIssueFromRemote;
use App\Http\Controllers\Controller;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\IssueComment;
use App\Services\Integrations\GithubIssueProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class GithubWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Connection $connection,
        GithubIssueProvider $github,
        SyncIssueFromRemote $sync,
    ): Response {
        abort_unless($this->signatureIsValid($request, $connection), 403);

        $event = $request->header('X-GitHub-Event');
        $payload = $request->all();
        $fullName = $payload['repository']['full_name'] ?? null;

        if (! is_string($fullName)) {
            return response()->noContent();
        }

        $source = $connection->sources()->where('external_id', $fullName)->first();

        if ($source === null) {
            return response()->noContent();
        }

        if ($event === 'issues') {
            $this->syncIssue($source, $payload, $github, $sync);
        }

        if ($event === 'issue_comment') {
            $this->syncComment($source, $payload, $github, $sync);
        }

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function syncIssue(
        ConnectedSource $source,
        array $payload,
        GithubIssueProvider $github,
        SyncIssueFromRemote $sync,
    ): void {
        $issuePayload = $payload['issue'] ?? [];

        if (! is_array($issuePayload) || isset($issuePayload['pull_request'])) {
            return;
        }

        $sync->handle($source, $github->mapIssue($issuePayload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function syncComment(
        ConnectedSource $source,
        array $payload,
        GithubIssueProvider $github,
        SyncIssueFromRemote $sync,
    ): void {
        $issuePayload = $payload['issue'] ?? [];
        $commentPayload = $payload['comment'] ?? [];

        if (! is_array($issuePayload) || ! is_array($commentPayload) || isset($issuePayload['pull_request'])) {
            return;
        }

        $issue = $sync->handle($source, $github->mapIssue($issuePayload));
        $remote = $github->mapComment($commentPayload);

        if (($payload['action'] ?? '') === 'deleted') {
            IssueComment::query()
                ->where('issue_id', $issue->id)
                ->where('external_id', $remote->externalId)
                ->delete();

            return;
        }

        $sync->syncComments($issue, collect([$remote]));
    }

    protected function signatureIsValid(Request $request, Connection $connection): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        $secret = $connection->webhook_secret;

        if (! is_string($signature) || blank($secret)) {
            Log::warning('GitHub webhook signature missing', ['connection_id' => $connection->id]);

            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
