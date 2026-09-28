<?php

use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Client;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('an owner can connect one github repository to a project', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
    ]);

    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => 'ruelluna']),
        'https://api.github.com/user/orgs*' => Http::response([
            ['login' => 'acme'],
        ]),
        'https://api.github.com/orgs/acme/repos*' => Http::response([
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
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.integrations', ['project' => $project])
        ->set('name', 'Client GitHub')
        ->set('token', 'ghp_test_token_value_12345')
        ->call('loadAccounts')
        ->assertHasNoErrors()
        ->assertSet('tokenVerified', true)
        ->assertSet('accounts', [
            ['login' => 'ruelluna', 'personal' => true],
            ['login' => 'acme', 'personal' => false],
        ])
        ->set('selectedOwner', 'acme')
        ->assertSet('availableRepos', ['acme/api', 'acme/site'])
        ->set('selectedRepo', 'acme/api')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = Connection::query()->first();

    expect($connection)
        ->name->toBe('Client GitHub')
        ->provider->toBe(Provider::Github)
        ->team_id->toBe($team->id)
        ->project_id->toBe($project->id);

    expect($connection->sources()->pluck('external_id')->all())->toBe(['acme/api']);
    expect($project->fresh()->connected_source_id)->toBe($connection->sources()->first()->id);

    Queue::assertPushed(SyncConnectedSourceJob::class);
});

test('repositories for the selected account can be filtered by search', function () {
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);

    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => 'ruelluna']),
        'https://api.github.com/user/orgs*' => Http::response([
            ['login' => 'acme'],
        ]),
        'https://api.github.com/orgs/acme/repos*' => Http::response([
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
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.integrations', ['project' => $project])
        ->set('token', 'ghp_test_token_value_12345')
        ->call('loadAccounts')
        ->set('selectedOwner', 'acme')
        ->assertSet('availableRepos', ['acme/api', 'acme/site'])
        ->set('repoSearch', 'site')
        ->assertSet('filteredRepos', ['acme/site'])
        ->set('repoSearch', 'missing')
        ->assertSet('filteredRepos', []);
});

test('choosing a personal account lists its private repositories', function () {
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);

    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => 'ruelluna']),
        'https://api.github.com/user/orgs*' => Http::response([], 403),
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'ruelluna/public-app',
                'name' => 'public-app',
                'private' => false,
                'html_url' => 'https://github.com/ruelluna/public-app',
            ],
            [
                'full_name' => 'ruelluna/ahlbakery',
                'name' => 'ahlbakery',
                'private' => true,
                'html_url' => 'https://github.com/ruelluna/ahlbakery',
            ],
        ]),
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.integrations', ['project' => $project])
        ->set('token', 'ghp_test_token_value_12345')
        ->call('loadAccounts')
        ->assertSet('accounts', [
            ['login' => 'ruelluna', 'personal' => true],
        ])
        ->set('selectedOwner', 'ruelluna')
        ->assertSet('availableRepos', ['ruelluna/public-app', 'ruelluna/ahlbakery'])
        ->assertSet('repoPrivacy.ruelluna/ahlbakery', true)
        ->assertSee('ahlbakery');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/user/repos')
            && ($request->data()['affiliation'] ?? null) === 'owner,collaborator';
    });
});

test('a member cannot manage project integrations', function () {
    ['team' => $team, 'project' => $project] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));
    attachProjectMember($project, $developer);

    $this->actingAs($developer);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->assertDontSee('Connect GitHub')
        ->assertDontSee('Disconnect');

    Livewire::test('projects.integrations', ['project' => $project])
        ->assertForbidden();
});

test('a member cannot disconnect a project connection', function () {
    ['team' => $team, 'project' => $project, 'connection' => $connection] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $this->actingAs($developer);

    Livewire::test('projects.integrations', ['project' => $project])
        ->assertForbidden();

    $this->assertModelExists($connection);
});

test('a client does not see project integrations or the repository name', function () {
    ['team' => $team, 'project' => $project, 'source' => $source] = githubConnectionForOwner();

    $client = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme Corp',
    ]);

    $project->update([
        'client_id' => $client->id,
    ]);

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->assertDontSeeLivewire('projects.integrations')
        ->assertDontSee('Connect GitHub')
        ->assertDontSee($source->name);

    Livewire::test('pages::projects.index')
        ->assertSee($project->name)
        ->assertDontSee($source->name);
});
