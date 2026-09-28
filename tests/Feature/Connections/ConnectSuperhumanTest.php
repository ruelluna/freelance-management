<?php

use App\Enums\Provider;
use App\Enums\TeamRole;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function fakeSuperhumanCatalog(): void
{
    Http::fake(function ($request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/whoami')) {
            return Http::response([
                'loginId' => 'owner@docs.test',
                'name' => 'Owner',
            ]);
        }

        if (str_ends_with($path, '/columns')) {
            return Http::response([
                'items' => [
                    ['id' => 'c-title', 'name' => 'Task'],
                    ['id' => 'c-assignee', 'name' => 'Assignee'],
                    ['id' => 'c-status', 'name' => 'Status'],
                    ['id' => 'c-notes', 'name' => 'Notes'],
                ],
            ]);
        }

        if (str_ends_with($path, '/pages')) {
            return Http::response([
                'items' => [
                    ['id' => 'canvas-tasking', 'name' => 'Gerber Tasking'],
                    ['id' => 'canvas-other', 'name' => 'Other page', 'parent' => ['name' => 'Website']],
                ],
            ]);
        }

        if (str_contains($path, '/tables')) {
            return Http::response([
                'items' => [
                    [
                        'id' => 'table-board',
                        'name' => 'Gerber Project/Deliverables',
                        'parent' => ['id' => 'canvas-tasking', 'name' => 'Gerber Tasking'],
                    ],
                    [
                        'id' => 'table-other',
                        'name' => 'Other board',
                        'parent' => ['id' => 'canvas-tasking', 'name' => 'Gerber Tasking'],
                    ],
                    [
                        'id' => 'grid-elsewhere',
                        'name' => 'Elsewhere',
                        'parent' => ['id' => 'canvas-other', 'name' => 'Other page'],
                    ],
                ],
            ]);
        }

        if (str_ends_with($path, '/docs')) {
            return Http::response([
                'items' => [
                    ['id' => 'AbCDeFGH', 'name' => 'Client work'],
                    ['id' => 'XyZOther', 'name' => 'Other doc'],
                ],
            ]);
        }

        return Http::response([], 404);
    });
}

test('an owner can connect a superhuman board on a project and queue a sync', function () {
    Queue::fake();
    Http::preventStrayRequests();
    fakeSuperhumanCatalog();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
        'name' => 'Client site',
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.superhuman', ['project' => $project])
        ->assertSee('API token')
        ->set('name', 'Client docs')
        ->set('token', 'superhuman-token-value-123456')
        ->call('loadDocs')
        ->assertHasNoErrors()
        ->assertSet('tokenVerified', true)
        ->set('docId', 'AbCDeFGH')
        ->call('docChanged')
        ->set('pageId', 'canvas-tasking')
        ->call('pageChanged')
        ->set('boardId', 'table-board')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = Connection::query()->first();

    expect($connection)
        ->name->toBe('Client docs')
        ->provider->toBe(Provider::Superhuman)
        ->team_id->toBe($owner->currentTeam->id)
        ->user_id->toBe($owner->id)
        ->project_id->toBe($project->id)
        ->token->toBe('superhuman-token-value-123456')
        ->settings->toMatchArray(['owner_email' => 'owner@docs.test']);

    $source = $connection->sources()->first();

    expect($source)
        ->name->toBe('Gerber Tasking / Gerber Project/Deliverables')
        ->external_id->toBe('AbCDeFGH/table-board')
        ->settings->toMatchArray([
            'page_id' => 'canvas-tasking',
            'match_emails' => ['owner@docs.test', 'owner@example.com'],
        ]);

    Queue::assertPushed(SyncConnectedSourceJob::class, fn (SyncConnectedSourceJob $job): bool => $job->sourceId === $source->id);
});

test('updating the token on a project keeps a single connection', function () {
    Queue::fake();
    Http::preventStrayRequests();
    fakeSuperhumanCatalog();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
        'name' => 'Client site',
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.superhuman', ['project' => $project])
        ->set('token', 'superhuman-token-value-123456')
        ->call('loadDocs')
        ->set('docId', 'AbCDeFGH')
        ->call('docChanged')
        ->set('pageId', 'canvas-tasking')
        ->call('pageChanged')
        ->set('boardId', 'table-board')
        ->call('connect')
        ->assertHasNoErrors();

    Livewire::test('projects.superhuman', ['project' => $project])
        ->set('token', 'superhuman-token-value-654321')
        ->call('loadDocs')
        ->assertHasNoErrors();

    expect(Connection::query()->count())->toBe(1);
    expect(Connection::query()->first())
        ->token->toBe('superhuman-token-value-654321')
        ->project_id->toBe($project->id);
});

test('a rejected superhuman token is not saved', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://docs.superhuman.com/apis/v1/whoami' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.superhuman', ['project' => $project])
        ->set('token', 'superhuman-token-value-123456')
        ->call('loadDocs')
        ->assertHasErrors('token');

    expect(Connection::query()->count())->toBe(0);
});

test('replacing the board removes tasks imported from the previous board', function () {
    Queue::fake();
    Http::preventStrayRequests();
    fakeSuperhumanCatalog();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
        'name' => 'Client site',
    ]);

    $this->actingAs($owner);

    $component = Livewire::test('projects.superhuman', ['project' => $project])
        ->set('token', 'superhuman-token-value-123456')
        ->call('loadDocs')
        ->set('docId', 'AbCDeFGH')
        ->call('docChanged')
        ->set('pageId', 'canvas-tasking')
        ->call('pageChanged')
        ->set('boardId', 'table-board')
        ->call('connect')
        ->assertHasNoErrors();

    $source = Connection::query()->first()->sources()->first();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $source->connection_id,
        'team_id' => $source->team_id,
        'project_id' => $project->id,
        'external_id' => 'i-old',
        'number' => null,
        'title' => 'Old board task',
    ]);

    $component
        ->set('boardId', 'table-other')
        ->call('saveSource')
        ->assertHasNoErrors();

    expect(Connection::query()->first()->sources()->pluck('external_id')->all())
        ->toBe(['AbCDeFGH/table-other']);

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeFalse();
});

test('an embedded page lists the table from the doc that owns it', function () {
    Queue::fake();
    Http::preventStrayRequests();

    Http::fake(function ($request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/whoami')) {
            return Http::response(['loginId' => 'owner@docs.test', 'name' => 'Owner']);
        }

        if (str_contains($path, '/pages/canvas-embed')) {
            return Http::response([
                'id' => 'canvas-embed',
                'name' => 'Gerber | Tasking Dashboard',
                'contentType' => 'embed',
            ]);
        }

        if (str_ends_with($path, '/pages') && str_contains($path, '/EmbedDoc/')) {
            return Http::response([
                'items' => [
                    ['id' => 'canvas-embed', 'name' => 'Gerber | Tasking Dashboard', 'contentType' => 'embed'],
                ],
            ]);
        }

        if (str_ends_with($path, '/pages') && str_contains($path, '/OwnerDoc/')) {
            return Http::response([
                'items' => [
                    ['id' => 'canvas-real', 'name' => 'Gerber | Tasking Dashboard', 'contentType' => 'canvas'],
                ],
            ]);
        }

        if (str_contains($path, '/tables') && str_contains($path, '/EmbedDoc/')) {
            return Http::response(['items' => []]);
        }

        if (str_contains($path, '/tables') && str_contains($path, '/OwnerDoc/')) {
            return Http::response([
                'items' => [
                    [
                        'id' => 'table-TWaPSiInSV',
                        'name' => 'Gerber Project/Deliverables',
                        'tableType' => 'view',
                        'parent' => ['id' => 'canvas-real', 'type' => 'page', 'name' => 'Gerber | Tasking Dashboard'],
                    ],
                ],
            ]);
        }

        if (str_ends_with($path, '/docs')) {
            return Http::response([
                'items' => [
                    ['id' => 'EmbedDoc', 'name' => 'Gerbers Home Furnishing (Active)'],
                    ['id' => 'OwnerDoc', 'name' => 'Master Project Management Tracker'],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('projects.superhuman', ['project' => $project])
        ->set('token', 'superhuman-token-value-123456')
        ->call('loadDocs')
        ->set('docId', 'EmbedDoc')
        ->call('docChanged')
        ->set('pageId', 'canvas-embed')
        ->call('pageChanged')
        ->assertSet('boards.0.name', 'Gerber Project/Deliverables')
        ->assertSet('boards.0.doc_id', 'OwnerDoc')
        ->set('boardId', 'table-TWaPSiInSV')
        ->call('connect')
        ->assertHasNoErrors();

    expect(Connection::query()->first()->sources()->first())
        ->external_id->toBe('OwnerDoc/table-TWaPSiInSV')
        ->name->toBe('Gerber | Tasking Dashboard / Gerber Project/Deliverables')
        ->settings->toMatchArray(['selected_doc_id' => 'EmbedDoc']);
});

test('a member cannot connect superhuman docs on a project', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->create([
        'team_id' => $owner->currentTeam->id,
    ]);
    $member = attachTeamMember($owner->currentTeam, User::factory()->create([
        'email' => 'member@example.com',
    ]), TeamRole::Member);

    $this->actingAs($member);

    Livewire::test('projects.superhuman', ['project' => $project])
        ->assertForbidden();
});
