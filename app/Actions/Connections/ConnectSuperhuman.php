<?php

namespace App\Actions\Connections;

use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\Integrations\SuperhumanIssueProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConnectSuperhuman
{
    public function __construct(private SuperhumanIssueProvider $superhuman) {}

    public function handle(Project $project, User $user, string $token, string $name): Connection
    {
        $email = $this->superhuman->ownerEmail($token);

        $connection = DB::transaction(function () use ($project, $user, $token, $name, $email): Connection {
            $connection = $project->connections()
                ->where('provider', Provider::Superhuman)
                ->first();

            $settings = $connection?->settings ?? [];
            $settings['owner_email'] = $email;

            $attributes = [
                'name' => $name !== '' ? $name : 'Superhuman Docs',
                'token' => $token,
                'user_id' => $user->id,
                'settings' => $settings,
            ];

            if ($connection === null) {
                return $project->connections()->create([
                    ...$attributes,
                    'team_id' => $project->team_id,
                    'provider' => Provider::Superhuman,
                ]);
            }

            $connection->update($attributes);

            return $connection;
        });

        Log::info('Superhuman Docs connection saved', [
            'connection_id' => $connection->id,
            'project_id' => $project->id,
            'team_id' => $project->team_id,
            'user_id' => $user->id,
        ]);

        return $connection;
    }
}
