<?php

use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Client;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
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

    Livewire::test('projects.integrations', ['project' => $project])
        ->set('name', 'Client GitHub')
        ->set('token', 'ghp_test_token_value_12345')
        ->call('fetchRepos')
        ->assertHasNoErrors()
        ->assertSet('tokenVerified', true)
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

test('loaded repositories can be filtered by search', function () {
    Http::preventStrayRequests();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);

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

    Livewire::test('projects.integrations', ['project' => $project])
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
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);

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

    Livewire::test('projects.integrations', ['project' => $project])
        ->set('token', 'ghp_test_token_value_12345')
        ->call('fetchRepos')
        ->assertSet('availableRepos', ['acme/public-app'])
        ->set('repoSearch', 'secret')
        ->assertSet('filteredRepos', ['acme/secret-app'])
        ->assertSet('repoPrivacy.acme/secret-app', true);
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
