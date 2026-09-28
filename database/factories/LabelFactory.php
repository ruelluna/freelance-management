<?php

namespace Database\Factories;

use App\Models\Label;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->slug(1),
            'color' => ltrim(fake()->hexColor(), '#'),
        ];
    }
}
