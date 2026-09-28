<?php

namespace Database\Factories;

use App\Models\ConnectedSource;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedSource>
 */
class ConnectedSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fullName = fake()->unique()->bothify('acme/repo-##');

        return [
            'connection_id' => Connection::factory(),
            'team_id' => fn (array $attributes): int => Connection::query()->findOrFail($attributes['connection_id'])->team_id,
            'external_id' => $fullName,
            'name' => $fullName,
            'settings' => null,
            'last_synced_at' => null,
        ];
    }
}
