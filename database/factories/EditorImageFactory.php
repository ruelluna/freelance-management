<?php

namespace Database\Factories;

use App\Models\EditorImage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EditorImage>
 */
class EditorImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'editor-images/example.png',
            'mime_type' => 'image/png',
        ];
    }
}
