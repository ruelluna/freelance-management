<?php

namespace App\Actions\Connections;

use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Team;
use App\Services\Integrations\GithubIssueProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConnectGithub
{
    public function __construct(private GithubIssueProvider $github) {}

    /**
     * @param  array<int, string>  $repoFullNames
     */
    public function handle(Team $team, string $token, string $name, array $repoFullNames): Connection
    {
        $sourceIds = [];

        $connection = DB::transaction(function () use ($team, $token, $name, $repoFullNames, &$sourceIds): Connection {
            $connection = $team->connections()->create([
                'provider' => Provider::Github,
                'name' => $name !== '' ? $name : 'GitHub',
                'token' => $token,
                'webhook_secret' => Str::random(40),
            ]);

            foreach ($repoFullNames as $fullName) {
                $source = $connection->sources()->create([
                    'team_id' => $team->id,
                    'external_id' => $fullName,
                    'name' => $fullName,
                ]);

                $this->github->registerWebhook($connection, $source);

                $sourceIds[] = $source->id;
            }

            return $connection;
        });

        foreach ($sourceIds as $sourceId) {
            SyncConnectedSourceJob::dispatch($sourceId);
        }

        Log::info('GitHub connection created', [
            'connection_id' => $connection->id,
            'team_id' => $team->id,
            'sources' => count($sourceIds),
        ]);

        return $connection;
    }
}
