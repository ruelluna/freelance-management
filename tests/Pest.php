<?php

use App\Enums\TeamRole;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @return array{owner: User, team: Team, connection: Connection, source: ConnectedSource}
 */
function githubConnectionForOwner(?User $owner = null): array
{
    $owner ??= User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'name' => 'GitHub',
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'acme/api',
        'name' => 'acme/api',
    ]);

    return compact('owner', 'team', 'connection', 'source');
}

function attachTeamMember(Team $team, User $member, TeamRole $role = TeamRole::Member): User
{
    $team->members()->attach($member, ['role' => $role->value]);
    $member->switchTeam($team);

    return $member;
}

function githubIssuePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 1001,
        'number' => 12,
        'title' => 'Fix client login',
        'body' => 'Users cannot sign in.',
        'state' => 'open',
        'html_url' => 'https://github.com/acme/api/issues/12',
        'updated_at' => '2026-08-25T12:00:00Z',
        'labels' => [
            ['name' => 'website', 'color' => '0e8a16'],
        ],
        'assignees' => [
            ['login' => 'octocat'],
        ],
    ], $overrides);
}

function githubWebhook(Connection $connection, string $event, array $payload): TestResponse
{
    $content = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $content, $connection->webhook_secret);

    return test()->call(
        'POST',
        route('webhooks.github', $connection),
        server: [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => $event,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $content,
    );
}
