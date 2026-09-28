<?php

use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
 * @return array{owner: User, team: Team, project: Project, connection: Connection, source: ConnectedSource}
 */
function githubConnectionForOwner(?User $owner = null): array
{
    $owner ??= User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme API',
    ]);

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'name' => 'GitHub',
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'acme/api',
        'name' => 'acme/api',
    ]);

    $project->update([
        'connected_source_id' => $source->id,
    ]);

    return compact('owner', 'team', 'project', 'connection', 'source');
}

function attachTeamMember(Team $team, User $member, TeamRole $role = TeamRole::Member): User
{
    $team->members()->attach($member, ['role' => $role->value]);
    $member->switchTeam($team);

    return $member;
}

function attachProjectMember(Project $project, User $member): User
{
    $project->members()->syncWithoutDetaching([$member->id]);

    return $member;
}

function attachClientUser(Team $team, Client $client, User $user): User
{
    $team->members()->attach($user, ['role' => TeamRole::Client->value]);
    $client->users()->attach($user);
    $user->switchTeam($team);

    return $user;
}

/**
 * @return array{owner: User, team: Team, client: Client, assignedProject: Project, internalProject: Project}
 */
function clientPortalFixtures(?User $owner = null): array
{
    $owner ??= User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    $client = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme Corp',
    ]);

    $assignedProject = Project::factory()->create([
        'team_id' => $team->id,
        'client_id' => $client->id,
        'name' => 'Acme website',
    ]);

    $internalProject = Project::factory()->create([
        'team_id' => $team->id,
        'client_id' => null,
        'name' => 'Internal ops',
    ]);

    return compact('owner', 'team', 'client', 'assignedProject', 'internalProject');
}

/**
 * @param  array<int, array<string, mixed>>  $issues
 * @param  array<string, mixed>  $overrides
 */
function fakeGithubSyncRequests(array $issues = [], array $overrides = []): void
{
    $issues = $issues === [] ? [githubIssuePayload()] : $issues;

    Http::fake(array_merge([
        'https://api.github.com/repos/acme/api/issues/12' => Http::response([
            'body' => '<p>Issue html body</p>',
        ]),
        'https://api.github.com/repos/acme/api/issues/12/comments*' => Http::response([]),
        'https://api.github.com/repos/acme/api/issues?*' => Http::response($issues),
    ], $overrides));
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
