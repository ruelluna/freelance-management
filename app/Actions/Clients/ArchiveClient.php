<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use Illuminate\Support\Facades\Log;

class ArchiveClient
{
    public function handle(Client $client): Client
    {
        $client->update(['status' => ClientStatus::Archived]);

        Log::info('Client archived', [
            'client_id' => $client->id,
            'team_id' => $client->team_id,
        ]);

        return $client->fresh() ?? $client;
    }
}
