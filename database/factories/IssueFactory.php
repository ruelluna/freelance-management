<?php

namespace Database\Factories;

use App\Enums\IssueStatus;
use App\Models\ConnectedSource;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connected_source_id' => ConnectedSource::factory(),
            'connection_id' => fn (array $attributes): int => ConnectedSource::query()->findOrFail($attributes['connected_source_id'])->connection_id,
            'team_id' => fn (array $attributes): int => ConnectedSource::query()->findOrFail($attributes['connected_source_id'])->team_id,
            'external_id' => (string) fake()->unique()->numerify('########'),
            'number' => fake()->unique()->numberBetween(1, 99999),
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => IssueStatus::Open,
            'external_url' => fake()->url(),
            'external_updated_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => IssueStatus::Closed,
        ]);
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connected_source_id' => null,
            'connection_id' => null,
            'external_id' => null,
            'number' => null,
            'external_url' => null,
            'external_updated_at' => null,
            'last_synced_at' => null,
        ]);
    }
}
