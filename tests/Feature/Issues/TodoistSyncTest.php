<?php

use App\Actions\Issues\AddIssueComment;
use App\Actions\Issues\RefreshIssueFromRemote;
use App\Actions\Issues\UpdateIssue;
use App\Data\Integrations\IssueUpdate;
use App\Enums\CommentAudience;
use App\Enums\IssueStatus;
use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Integrations\IssueProviderFactory;
use App\Services\Integrations\TodoistIssueProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Date::setTestNow('2026-09-28 12:00:00');
});

/**
 * @return array{owner: User, connection: Connection, source: ConnectedSource}
 */
function todoistSourceForOwner(): array
{
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'project_id' => null,
        'provider' => Provider::Todoist,
        'name' => 'Todoist',
        'token' => 'todoist-token-value-123456',
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'all',
        'name' => 'All tasks',
    ]);

    return compact('owner', 'connection', 'source');
}

/**
 * @return array<string, mixed>
 */
function todoistTask(string $id, string $content, ?string $description = null, string $addedAt = '2026-08-15T12:00:00+00:00'): array
{
    return [
        'id' => $id,
        'content' => $content,
        'description' => $description ?? '',
        'checked' => false,
        'added_at' => $addedAt,
        'updated_at' => $addedAt,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $tasks
 * @param  array<int, array<string, mixed>>  $completed
 * @param  array<int, array<string, mixed>>  $beforeCutoff
 * @param  array<int, array<string, mixed>>  $comments
 */
function fakeTodoistSync(array $tasks = [], array $completed = [], array $beforeCutoff = [], array $comments = []): void
{
    Http::fake(function ($request) use ($tasks, $completed, $beforeCutoff, $comments) {
        $url = $request->url();

        if (str_contains($url, '/comments')) {
            return Http::response([
                'results' => $comments,
                'next_cursor' => null,
            ]);
        }

        if (str_contains($url, '/tasks/completed')) {
            return Http::response([
                'items' => $completed,
                'next_cursor' => null,
            ]);
        }

        if (str_contains($url, '/tasks/filter')) {
            $query = (string) ($request->data()['query'] ?? '');
            $createdBeforeOnly = str_contains($query, 'created before:') && ! str_contains($query, 'created after:');

            return Http::response([
                'results' => $createdBeforeOnly ? $beforeCutoff : $tasks,
                'next_cursor' => null,
            ]);
        }

        return Http::response([], 404);
    });
}

test('open todoist tasks are imported as issues with no project', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source] = todoistSourceForOwner();

    fakeTodoistSync(
        tasks: [todoistTask('task-1', 'Buy milk', 'Organic if they have it')],
        comments: [[
            'id' => 'note-1',
            'content' => 'From the shop on the corner',
            'posted_at' => now()->toIso8601String(),
            'is_deleted' => false,
        ]],
    );

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    $issue = Issue::query()->where('external_id', 'task-1')->first();

    expect($issue)
        ->not->toBeNull()
        ->project_id->toBeNull()
        ->created_by->toBe($owner->id)
        ->title->toBe('Buy milk')
        ->body->toBe('Organic if they have it')
        ->status->toBe(IssueStatus::Open)
        ->number->toBeNull()
        ->external_url->toBe('https://app.todoist.com/app/task/task-1');

    expect($issue->comments()->first())
        ->body->toBe('From the shop on the corner')
        ->author_name->toBe('Todoist');
});

test('the first sync keeps tasks created since july 2026 and removes older ones', function () {
    Http::preventStrayRequests();

    ['source' => $source] = todoistSourceForOwner();

    $older = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $source->connection_id,
        'team_id' => $source->team_id,
        'project_id' => null,
        'external_id' => 'old-task',
        'number' => null,
        'title' => 'Already imported',
        'status' => IssueStatus::Open,
    ]);

    fakeTodoistSync(
        tasks: [
            todoistTask('task-1', 'Buy milk'),
            todoistTask('old-task', 'Already imported', addedAt: '2026-06-01T12:00:00+00:00'),
        ],
        beforeCutoff: [
            todoistTask('old-task', 'Already imported', addedAt: '2026-06-01T12:00:00+00:00'),
        ],
    );

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    expect(Issue::query()->where('external_id', 'task-1')->exists())->toBeTrue();
    expect(Issue::query()->whereKey($older->id)->exists())->toBeFalse();
    expect($source->fresh()->settings['initial_import_at'] ?? null)->not->toBeNull();
});

test('later syncs import only tasks created after the previous sync', function () {
    Http::preventStrayRequests();

    ['source' => $source] = todoistSourceForOwner();

    fakeTodoistSync(tasks: [
        todoistTask('task-1', 'Buy milk'),
        todoistTask('task-2', 'Call the client'),
    ]);

    $job = new SyncConnectedSourceJob($source->id);
    $providers = app(IssueProviderFactory::class);
    $refresh = app(RefreshIssueFromRemote::class);

    $job->handle($providers, $refresh);

    Date::setTestNow('2026-09-28 18:00:00');

    fakeTodoistSync(tasks: [
        todoistTask('task-1', 'Buy milk'),
        todoistTask('task-3', 'Send the invoice', addedAt: '2026-09-28T15:00:00+00:00'),
    ]);

    $job->handle($providers, $refresh);

    expect(Issue::query()->where('external_id', 'task-1')->first()->status)->toBe(IssueStatus::Open);
    expect(Issue::query()->where('external_id', 'task-2')->first()->status)->toBe(IssueStatus::Open);
    expect(Issue::query()->where('external_id', 'task-3')->exists())->toBeTrue();
});

test('a task completed in todoist is closed locally', function () {
    Http::preventStrayRequests();

    ['source' => $source] = todoistSourceForOwner();

    fakeTodoistSync(tasks: [todoistTask('task-1', 'Buy milk')]);

    $job = new SyncConnectedSourceJob($source->id);
    $providers = app(IssueProviderFactory::class);
    $refresh = app(RefreshIssueFromRemote::class);

    $job->handle($providers, $refresh);

    fakeTodoistSync(
        tasks: [],
        completed: [todoistTask('task-1', 'Buy milk')],
    );

    $job->handle($providers, $refresh);

    expect(Issue::query()->where('external_id', 'task-1')->first()->status)->toBe(IssueStatus::Closed);
});

test('closing and renaming a task is pushed to todoist', function () {
    Http::preventStrayRequests();

    ['source' => $source] = todoistSourceForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $source->connection_id,
        'team_id' => $source->team_id,
        'project_id' => null,
        'external_id' => 'task-1',
        'number' => null,
        'title' => 'Buy milk',
        'status' => IssueStatus::Open,
    ]);

    Http::fake([
        'https://api.todoist.com/api/v1/tasks/task-1' => Http::response(todoistTask('task-1', 'Buy oat milk')),
        'https://api.todoist.com/api/v1/tasks/task-1/close' => Http::response(null, 200),
    ]);

    app(UpdateIssue::class)->handle($issue, new IssueUpdate(
        status: IssueStatus::Closed->value,
        title: 'Buy oat milk',
    ));

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.todoist.com/api/v1/tasks/task-1'
            && $request['content'] === 'Buy oat milk';
    });

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.todoist.com/api/v1/tasks/task-1/close');

    expect($issue->fresh()->status)->toBe(IssueStatus::Closed);
    expect($issue->fresh()->title)->toBe('Buy oat milk');
});

test('a local comment is pushed to the todoist task', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source] = todoistSourceForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $source->connection_id,
        'team_id' => $source->team_id,
        'project_id' => null,
        'external_id' => 'task-1',
        'number' => null,
        'title' => 'Buy milk',
    ]);

    Http::fake([
        'https://api.todoist.com/api/v1/comments' => Http::response([
            'id' => 'note-9',
            'content' => 'On it',
            'posted_at' => now()->toIso8601String(),
        ]),
    ]);

    app(AddIssueComment::class)->handle($issue, $owner, 'On it', CommentAudience::Internal);

    expect($issue->comments()->first()->external_id)->toBe('note-9');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.todoist.com/api/v1/comments'
        && $request['task_id'] === 'task-1'
        && $request['content'] === 'On it');
});

test('syncing todoist does not remove a local assignee', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source] = todoistSourceForOwner();

    UserIdentity::query()->create([
        'user_id' => $owner->id,
        'provider' => Provider::Github,
        'external_id' => 'ruell',
    ]);

    fakeTodoistSync(tasks: [todoistTask('task-1', 'Buy milk')]);

    $job = new SyncConnectedSourceJob($source->id);
    $providers = app(IssueProviderFactory::class);
    $refresh = app(RefreshIssueFromRemote::class);

    $job->handle($providers, $refresh);

    $issue = Issue::query()->where('external_id', 'task-1')->first();
    $issue->assignees()->attach($owner);

    $job->handle($providers, $refresh);

    expect($issue->fresh()->assignees()->pluck('users.id')->all())->toBe([$owner->id]);
});

test('the scheduled sync command queues the todoist source', function () {
    Queue::fake();

    ['source' => $source] = todoistSourceForOwner();

    $this->artisan('issues:sync')->assertSuccessful();

    Queue::assertPushed(SyncConnectedSourceJob::class, fn (SyncConnectedSourceJob $job): bool => $job->sourceId === $source->id);
});

test('creating a todoist task from the provider posts to the inbox', function () {
    Http::preventStrayRequests();

    ['source' => $source] = todoistSourceForOwner();

    Http::fake([
        'https://api.todoist.com/api/v1/tasks' => Http::response(todoistTask('task-9', 'Write notes', 'Bring the brief')),
    ]);

    $remote = app(TodoistIssueProvider::class)->createIssue($source, 'Write notes', 'Bring the brief');

    expect($remote->externalId)->toBe('task-9');
    expect($remote->title)->toBe('Write notes');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.todoist.com/api/v1/tasks'
        && $request['content'] === 'Write notes'
        && $request['description'] === 'Bring the brief'
        && ! array_key_exists('project_id', $request->data()));
});
