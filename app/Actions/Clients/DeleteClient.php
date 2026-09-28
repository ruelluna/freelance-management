<?php

namespace App\Actions\Clients;

use App\Enums\TeamRole;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteClient
{
    public function handle(Client $client): void
    {
        $clientId = $client->id;
        $teamId = $client->team_id;

        DB::transaction(function () use ($client) {
            $userIds = $client->users()->pluck('users.id');

            $client->users()->detach();

            foreach ($userIds as $userId) {
                $membership = $client->team->memberships()
                    ->where('user_id', $userId)
                    ->first();

                if ($membership?->role === TeamRole::Client) {
                    $membership->delete();
                }
            }

            $client->delete();
        });

        Log::info('Client deleted', [
            'client_id' => $clientId,
            'team_id' => $teamId,
        ]);
    }
}
