<?php

namespace App\Actions\Connections;

use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Project;
use App\Services\Integrations\GithubIssueProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConnectGithub
{
    public function __construct(private GithubIssueProvider $github) {}

    public function handle(Project $project, string $token, string $name, string $repoFullName): Connection
    {
        if ($project->connections()->where('provider', Provider::Github)->exists()) {
            throw ValidationException::withMessages([
                'selectedRepo' => __('This project already has a GitHub connection.'),
            ]);
        }

        $sourceId = null;

        $connection = DB::transaction(function () use ($project, $token, $name, $repoFullName, &$sourceId): Connection {
            $connection = $project->connections()->create([
                'team_id' => $project->team_id,
                'provider' => Provider::Github,
                'name' => $name !== '' ? $name : 'GitHub',
                'token' => $token,
                'webhook_secret' => Str::random(40),
            ]);

            $source = $connection->sources()->create([
                'team_id' => $project->team_id,
                'external_id' => $repoFullName,
                'name' => $repoFullName,
            ]);

            $this->github->registerWebhook($connection, $source);

            $project->update([
                'connected_source_id' => $source->id,
            ]);

            $sourceId = $source->id;

            return $connection;
        });

        if ($sourceId !== null) {
            SyncConnectedSourceJob::dispatch($sourceId);
        }

        Log::info('GitHub connection created', [
            'connection_id' => $connection->id,
            'project_id' => $project->id,
            'team_id' => $project->team_id,
        ]);

        return $connection;
    }
}
