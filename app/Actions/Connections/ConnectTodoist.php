<?php

namespace App\Actions\Connections;

use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\TodoistIssueProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConnectTodoist
{
    public function __construct(private TodoistIssueProvider $todoist) {}

    public function handle(Team $team, User $user, string $token, string $name): Connection
    {
        $this->todoist->verifyToken($token);

        $sourceId = null;

        $connection = DB::transaction(function () use ($team, $user, $token, $name, &$sourceId): Connection {
            $connection = $team->connections()
                ->where('provider', Provider::Todoist)
                ->where('user_id', $user->id)
                ->first();

            $attributes = [
                'name' => $name !== '' ? $name : 'Todoist',
                'token' => $token,
            ];

            if ($connection === null) {
                $connection = $team->connections()->create([
                    ...$attributes,
                    'user_id' => $user->id,
                    'project_id' => null,
                    'provider' => Provider::Todoist,
                ]);
            } else {
                $connection->update($attributes);
            }

            $source = $connection->sources()->first();

            if ($source === null) {
                $source = $connection->sources()->create([
                    'team_id' => $team->id,
                    'external_id' => 'all',
                    'name' => 'All tasks',
                ]);
            }

            $sourceId = $source->id;

            return $connection;
        });

        if ($sourceId !== null) {
            SyncConnectedSourceJob::dispatch($sourceId);
        }

        Log::info('Todoist connection saved', [
            'connection_id' => $connection->id,
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        return $connection;
    }
}
