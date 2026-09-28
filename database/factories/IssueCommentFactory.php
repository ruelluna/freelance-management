<?php

namespace Database\Factories;

use App\Enums\CommentAudience;
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
            'parent_id' => null,
            'user_id' => null,
            'external_id' => (string) fake()->unique()->numerify('########'),
            'body' => fake()->paragraph(),
            'author_name' => fake()->name(),
            'origin' => CommentOrigin::Remote,
            'audience' => CommentAudience::Internal,
            'shared_at' => null,
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

    public function clientThread(): static
    {
        return $this->state(fn (array $attributes): array => [
            'external_id' => null,
            'origin' => CommentOrigin::Local,
            'audience' => CommentAudience::Client,
            'shared_at' => null,
            'synced_at' => null,
        ]);
    }

    public function sharedWithTeam(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audience' => CommentAudience::Client,
            'shared_at' => now(),
        ]);
    }
}
