<?php

namespace Database\Factories;

use App\Enums\CommentOrigin;
use App\Models\Issue;
use App\Models\IssueComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueComment>
 */
class IssueCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'user_id' => null,
            'external_id' => (string) fake()->unique()->numerify('########'),
            'body' => fake()->paragraph(),
            'author_name' => fake()->name(),
            'origin' => CommentOrigin::Remote,
            'synced_at' => now(),
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes): array => [
            'external_id' => null,
            'origin' => CommentOrigin::Local,
            'synced_at' => null,
        ]);
    }
}
