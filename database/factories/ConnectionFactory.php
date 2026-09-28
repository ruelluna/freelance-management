<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'project_id' => function (array $attributes): string {
                $teamId = $attributes['team_id'] instanceof Team
                    ? $attributes['team_id']->id
                    : $attributes['team_id'];

                return Project::factory()->create([
                    'team_id' => $teamId,
                ])->id;
            },
            'provider' => Provider::Github,
            'name' => 'GitHub',
            'token' => 'ghp_'.Str::random(20),
            'webhook_secret' => Str::random(40),
            'settings' => null,
            'last_synced_at' => null,
        ];
    }
}
