<?php

use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('an owner can connect github repositories', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/api',
                'name' => 'api',
                'private' => false,
                'html_url' => 'https://github.com/acme/api',
            ],
            [
                'full_name' => 'acme/site',
                'name' => 'site',
                'private' => true,
                'html_url' => 'https://github.com/acme/site',
            ],
        ]),
        'https://api.github.com/search/repositories*' => Http::response(['items' => []]),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::connections.index')
        ->set('name', 'Client GitHub')
        ->set('token', 'ghp_test_token_value_12345')
        ->call('fetchRepos')
        ->assertHasNoErrors()
        ->assertSet('tokenVerified', true)
        ->set('selectedRepos', ['acme/api'])
        ->call('connect')
        ->assertHasNoErrors();

    $connection = Connection::query()->first();

    expect($connection)
        ->name->toBe('Client GitHub')
        ->provider->toBe(Provider::Github)
        ->team_id->toBe($team->id);

    expect($connection->sources()->pluck('external_id')->all())->toBe(['acme/api']);

    Queue::assertPushed(SyncConnectedSourceJob::class);
});

test('loaded repositories can be filtered by search', function () {
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);

    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/api',
                'name' => 'api',
                'private' => false,
                'html_url' => 'https://github.com/acme/api',
            ],
            [
                'full_name' => 'acme/site',
                'name' => 'site',
                'private' => true,
                'html_url' => 'https://github.com/acme/site',
            ],
            [
                'full_name' => 'other-org/docs',
                'name' => 'docs',
                'private' => false,
                'html_url' => 'https://github.com/other-org/docs',
            ],
        ]),
        'https://api.github.com/search/repositories*' => Http::response(['items' => []]),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::connections.index')
        ->set('token', 'ghp_test_token_value_12345')
        ->call('fetchRepos')
        ->assertSet('availableRepos', ['acme/api', 'acme/site', 'other-org/docs'])
        ->set('repoSearch', 'acme')
        ->assertSet('filteredRepos', ['acme/api', 'acme/site'])
        ->set('repoSearch', 'docs')
        ->assertSet('filteredRepos', ['other-org/docs'])
        ->set('repoSearch', 'missing')
        ->assertSet('filteredRepos', []);
});

test('repository search can find private repos via github search api', function () {
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);

    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/public-app',
                'name' => 'public-app',
                'private' => false,
                'html_url' => 'https://github.com/acme/public-app',
            ],
        ]),
        'https://api.github.com/search/repositories*' => Http::response([
            'items' => [
                [
                    'full_name' => 'acme/secret-app',
                    'name' => 'secret-app',
                    'private' => true,
                    'html_url' => 'https://github.com/acme/secret-app',
                ],
            ],
        ]),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::connections.index')
        ->set('token', 'ghp_test_token_value_12345')
        ->call('fetchRepos')
        ->assertSet('availableRepos', ['acme/public-app'])
        ->set('repoSearch', 'secret')
        ->assertSet('filteredRepos', ['acme/secret-app'])
        ->assertSet('repoPrivacy.acme/secret-app', true);
});

test('a member cannot manage connections', function () {
    ['team' => $team] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $this->actingAs($developer);

    Livewire::test('pages::connections.index')
        ->set('token', 'ghp_test_token_value_12345')
        ->call('fetchRepos')
        ->assertForbidden();
});

test('a member cannot disconnect a connection', function () {
    ['team' => $team, 'connection' => $connection] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $this->actingAs($developer);

    Livewire::test('pages::connections.index')
        ->call('disconnect', $connection->id)
        ->assertForbidden();

    $this->assertModelExists($connection);
});
