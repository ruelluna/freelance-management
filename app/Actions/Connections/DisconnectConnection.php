<?php

namespace App\Actions\Connections;

use App\Enums\Provider;
use App\Models\Connection;
use App\Services\Integrations\GithubIssueProvider;
use Illuminate\Support\Facades\Log;

class DisconnectConnection
{
    public function __construct(private GithubIssueProvider $github) {}

    public function handle(Connection $connection): void
    {
        if ($connection->provider === Provider::Github) {
            $connection->load('sources.connection');

            foreach ($connection->sources as $source) {
                $this->github->deleteWebhook($source);
            }
        }

        $connection->delete();

        Log::info('Connection disconnected', [
            'connection_id' => $connection->id,
            'provider' => $connection->provider->value,
        ]);
    }
}
