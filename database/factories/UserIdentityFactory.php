<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIdentity>
 */
class UserIdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => Provider::Github,
            'external_id' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
