<?php

use App\Actions\Issues\RefreshIssueFromRemote;
use App\Enums\IssueStatus;
use App\Enums\Provider;
use App\Enums\TeamRole;
use App\Jobs\PushCommentToSource;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Project;
use App\Models\User;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * @return array{owner: User, connection: Connection, source: ConnectedSource, project: Project}
 */
function superhumanSource(): array
{
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
    ]);

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'project_id' => $project->id,
        'provider' => Provider::Superhuman,
        'name' => 'Client docs',
        'token' => 'superhuman-token-value-123456',
        'settings' => ['owner_email' => 'owner@docs.test'],
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'AbCDeFGH/table-board',
        'name' => 'Gerber Tasking / Gerber Project/Deliverables',
        'settings' => [
            'page_id' => 'canvas-tasking',
            'page_name' => 'Gerber Tasking',
            'match_emails' => ['owner@docs.test', 'owner@example.com'],
            'closed_statuses' => ['Done', 'Complete', 'Completed', 'Closed'],
        ],
    ]);

    return compact('owner', 'connection', 'source', 'project');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function superhumanRow(string $id, string $title, string $email, string $status = 'In progress', ?string $notes = 'Details here'): array
{
    return [
        'id' => $id,
        'name' => $title,
        'browserLink' => 'https://docs.superhuman.com/d/_dAbCDeFGH#_'.$id,
        'updatedAt' => '2026-09-01T12:00:00.000Z',
        'values' => [
            'c-title' => '```'.$title.'```',
            'c-assignee' => [
                '@type' => 'Person',
                'name' => 'Someone',
                'email' => $email,
            ],
            'c-status' => $status,
            'c-notes' => $notes === null ? null : '```'.$notes.'```',
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function superhumanColumns(): array
{
    return [
        ['id' => 'c-title', 'name' => 'Task', 'format' => ['type' => 'text']],
        ['id' => 'c-assignee', 'name' => 'Assigned Into', 'format' => ['type' => 'person']],
        ['id' => 'c-status', 'name' => 'Status', 'format' => ['type' => 'select']],
        ['id' => 'c-notes', 'name' => 'Notes', 'format' => ['type' => 'text']],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function fakeSuperhumanRows(array $rows, string $nextSyncToken = 'sync-1'): void
{
    Http::fake(function ($request) use ($rows, $nextSyncToken) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/columns')) {
            return Http::response(['items' => superhumanColumns()]);
        }

        if (str_contains($path, '/rows')) {
            return Http::response([
                'items' => $rows,
                'nextSyncToken' => $nextSyncToken,
            ]);
        }

        return Http::response([], 404);
    });
}

test('assigned superhuman rows are imported and other people are skipped', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source, 'project' => $project] = superhumanSource();

    fakeSuperhumanRows([
        superhumanRow('i-mine', 'Write the brief', 'owner@docs.test', 'In progress', 'From the client doc'),
        superhumanRow('i-app', 'Follow up', 'owner@example.com'),
        superhumanRow('i-done', 'Ship the homepage', 'owner@docs.test', 'Done', 'Ready for review'),
        superhumanRow('i-other', 'Their task', 'someone@client.test'),
    ]);

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    $open = Issue::query()->where('external_id', 'table-board/i-mine')->first();

    expect($open)
        ->not->toBeNull()
        ->project_id->toBe($project->id)
        ->created_by->toBe($owner->id)
        ->title->toBe('Write the brief')
        ->body->toBe('From the client doc')
        ->status->toBe(IssueStatus::Open)
        ->number->toBeNull()
        ->external_url->toBe('https://docs.superhuman.com/d/_dAbCDeFGH#_i-mine');

    expect($open->assignees)->toHaveCount(0);
    expect($open->comments)->toHaveCount(0);

    expect(Issue::query()->where('external_id', 'table-board/i-done')->exists())->toBeFalse();

    expect(Issue::query()->where('external_id', 'table-board/i-app')->exists())->toBeTrue();
    expect(Issue::query()->where('external_id', 'table-board/i-other')->exists())->toBeFalse();
    expect($source->fresh()->settings['sync_token'])->toBe('sync-1');
});

test('a later sync sends the stored sync token', function () {
    Http::preventStrayRequests();

    ['source' => $source] = superhumanSource();

    fakeSuperhumanRows([
        superhumanRow('i-mine', 'Write the brief', 'owner@docs.test'),
    ], 'sync-1');

    $job = new SyncConnectedSourceJob($source->id);
    $job->handle(app(IssueProviderFactory::class), app(RefreshIssueFromRemote::class));

    $seen = null;

    Http::fake(function ($request) use (&$seen) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/columns')) {
            return Http::response(['items' => superhumanColumns()]);
        }

        if (str_contains($path, '/rows')) {
            $seen = $request->data()['syncToken'] ?? null;

            return Http::response([
                'items' => [],
                'nextSyncToken' => 'sync-2',
            ]);
        }

        return Http::response([], 404);
    });

    $job->handle(app(IssueProviderFactory::class), app(RefreshIssueFromRemote::class));

    expect($seen)->toBe('sync-1');
    expect($source->fresh()->settings['sync_token'])->toBe('sync-2');
});

test('a row that is no longer assigned to you is removed', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source, 'connection' => $connection] = superhumanSource();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'project_id' => $source->connection->project_id,
        'created_by' => $owner->id,
        'external_id' => 'table-board/i-other',
        'number' => null,
        'title' => 'Their task',
    ]);

    fakeSuperhumanRows([
        superhumanRow('i-other', 'Their task', 'someone@client.test'),
    ]);

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    expect(Issue::query()->whereKey($issue->id)->exists())->toBeFalse();
});

test('a local comment on a superhuman task is not pushed', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source, 'connection' => $connection] = superhumanSource();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'created_by' => $owner->id,
        'external_id' => 'i-mine',
        'number' => null,
        'title' => 'Write the brief',
    ]);

    $comment = IssueComment::factory()->local()->create([
        'issue_id' => $issue->id,
        'user_id' => $owner->id,
        'body' => 'Kept in this app',
    ]);

    (new PushCommentToSource($comment->id))->handle(app(IssueProviderFactory::class));

    expect($comment->fresh()->external_id)->toBeNull();
});

test('a project superhuman task is visible to other admins', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://docs.superhuman.com/*' => Http::response(['items' => []]),
    ]);
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $admin = attachTeamMember($team, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
    ]);

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'project_id' => $project->id,
        'provider' => Provider::Superhuman,
        'token' => 'superhuman-token-value-123456',
        'settings' => ['owner_email' => 'owner@docs.test'],
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'AbCDeFGH/grid-tasks',
        'name' => 'Client work / Tasks',
    ]);

    Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'external_id' => 'i-mine',
        'number' => null,
        'title' => 'Shared brief',
        'status' => IssueStatus::Open,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::issues.index')
        ->assertSee('Shared brief');

    Livewire::test('pages::projects.show', ['project' => $project])
        ->assertSee('Shared brief');
});
