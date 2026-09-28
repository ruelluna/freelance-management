<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

class CreateClient
{
    public function handle(
        Team $team,
        string $name,
        ?string $contactEmail,
        ?string $notes,
    ): Client {
        $client = $team->clients()->create([
            'name' => $name,
            'contact_email' => filled($contactEmail) ? $contactEmail : null,
            'notes' => filled($notes) ? $notes : null,
            'status' => ClientStatus::Active,
        ]);

        Log::info('Client created', [
            'client_id' => $client->id,
            'team_id' => $team->id,
        ]);

        return $client;
    }
}
