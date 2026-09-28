<?php

namespace App\Actions\Clients;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

class UpdateClient
{
    public function handle(
        Client $client,
        string $name,
        ?string $contactEmail,
        ?string $notes,
    ): Client {
        $client->update([
            'name' => $name,
            'contact_email' => filled($contactEmail) ? $contactEmail : null,
            'notes' => filled($notes) ? $notes : null,
        ]);

        Log::info('Client updated', [
            'client_id' => $client->id,
            'team_id' => $client->team_id,
        ]);

        return $client->fresh() ?? $client;
    }
}
