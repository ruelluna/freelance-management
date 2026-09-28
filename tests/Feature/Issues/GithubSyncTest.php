<?php

use App\Actions\Issues\RefreshIssueFromRemote;
use App\Actions\Issues\SyncIssueFromRemote;
use App\Data\Integrations\RemoteComment;
use App\Enums\CommentOrigin;
use App\Enums\IssueStatus;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Label;
use App\Models\UserIdentity;
use App\Services\Integrations\GithubIssueProvider;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('github sync upserts issues by external id, imports labels, and skips pull requests', function () {
    Http::preventStrayRequests();

    ['source' => $source, 'team' => $team] = githubConnectionForOwner();

    fakeGithubSyncRequests([
        githubIssuePayload(),
        githubIssuePayload([
            'id' => 2002,
            'number' => 13,
            'title' => 'Should be skipped',
            'pull_request' => ['url' => 'https://api.github.com/repos/acme/api/pulls/13'],
        ]),
    ]);

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    expect(Issue::query()->count())->toBe(1);

    $issue = Issue::query()->first();

    expect($issue)
        ->title->toBe('Fix client login')
        ->external_id->toBe('1001')
        ->number->toBe(12)
        ->status->toBe(IssueStatus::Open)
        ->team_id->toBe($team->id)
        ->body_html->toBe('<p>Issue html body</p>');

    $this->assertModelExists(Label::query()->where('team_id', $team->id)->where('name', 'website')->first());
    expect($issue->labels()->pluck('name')->all())->toContain('website');
});

test('github sync caches issue media from html bodies', function () {
    Http::preventStrayRequests();

    ['source' => $source] = githubConnectionForOwner();
    $assetUuid = '9498dba1-d084-4319-aafc-d35c0de16cb7';

    fakeGithubSyncRequests(
        overrides: [
            'https://api.github.com/repos/acme/api/issues/12' => Http::response([
                'body' => '<img src="https://private-user-images.githubusercontent.com/123/'.$assetUuid.'.png?jwt=abc" />',
            ]),
            'https://private-user-images.githubusercontent.com/*' => Http::response('png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ],
    );

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    $issue = Issue::query()->first();

    expect($issue->body_html)->toContain('/issues/'.$issue->id.'/media/'.$assetUuid);
    Storage::disk('local')->assertExists("issue-media/{$issue->id}/{$assetUuid}.png");
});

test('github sync is idempotent for the same external issue', function () {
    Http::preventStrayRequests();

    ['source' => $source] = githubConnectionForOwner();

    fakeGithubSyncRequests([
        githubIssuePayload(['title' => 'Updated title']),
    ]);

    $job = new SyncConnectedSourceJob($source->id);

    $job->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    $job->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    expect(Issue::query()->count())->toBe(1);
    expect(Issue::query()->first()->title)->toBe('Updated title');
});

test('github sync imports comments without duplicating existing remote comments', function () {
    Http::preventStrayRequests();

    ['source' => $source] = githubConnectionForOwner();

    fakeGithubSyncRequests(
        issues: [githubIssuePayload()],
        overrides: [
            'https://api.github.com/repos/acme/api/issues/12/comments*' => Http::response([
                [
                    'id' => 555,
                    'body' => 'Looks good',
                    'user' => ['login' => 'octocat'],
                    'created_at' => '2026-08-25T13:00:00Z',
                ],
            ]),
            'https://api.github.com/repos/acme/api/issues/comments/555' => Http::response([
                'body' => '<p>Looks good</p>',
            ]),
        ],
    );

    $job = new SyncConnectedSourceJob($source->id);
    $factory = app(IssueProviderFactory::class);
    $refresh = app(RefreshIssueFromRemote::class);

    $job->handle($factory, $refresh);
    $job->handle($factory, $refresh);

    expect(IssueComment::query()->count())->toBe(1);
    expect(IssueComment::query()->first()->external_id)->toBe('555');
});

test('github webhook rejects an invalid signature', function () {
    ['connection' => $connection] = githubConnectionForOwner();

    $this->postJson(route('webhooks.github', $connection), [
        'action' => 'opened',
        'issue' => githubIssuePayload(),
        'repository' => ['full_name' => 'acme/api'],
    ], [
        'X-Hub-Signature-256' => 'sha256=invalid',
        'X-GitHub-Event' => 'issues',
    ])->assertForbidden();

    expect(Issue::query()->count())->toBe(0);
});

test('github webhook upserts an issue and skips pull requests', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12' => Http::response(['body' => '<p>Issue html</p>']),
    ]);

    ['connection' => $connection] = githubConnectionForOwner();

    $payload = [
        'action' => 'opened',
        'issue' => githubIssuePayload(),
        'repository' => ['full_name' => 'acme/api'],
    ];

    githubWebhook($connection, 'issues', $payload)->assertNoContent();

    expect(Issue::query()->count())->toBe(1);

    $prPayload = [
        'action' => 'opened',
        'issue' => githubIssuePayload([
            'id' => 3003,
            'number' => 99,
            'title' => 'A pull request',
            'pull_request' => ['url' => 'https://api.github.com/repos/acme/api/pulls/99'],
        ]),
        'repository' => ['full_name' => 'acme/api'],
    ];

    githubWebhook($connection, 'issues', $prPayload)->assertNoContent();

    expect(Issue::query()->count())->toBe(1);
});

test('github webhook ignores a comment that already exists', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12' => Http::response(['body' => '<p>Issue html</p>']),
        'https://api.github.com/repos/acme/api/issues/comments/777' => Http::response(['body' => '<p>Already stored</p>']),
    ]);

    ['connection' => $connection, 'source' => $source] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'external_id' => '1001',
        'number' => 12,
    ]);

    IssueComment::factory()->create([
        'issue_id' => $issue->id,
        'external_id' => '777',
        'body' => 'Already stored',
    ]);

    $payload = [
        'action' => 'created',
        'issue' => githubIssuePayload(),
        'comment' => [
            'id' => 777,
            'body' => 'Already stored',
            'user' => ['login' => 'octocat'],
        ],
        'repository' => ['full_name' => 'acme/api'],
    ];

    githubWebhook($connection, 'issue_comment', $payload)->assertNoContent();

    expect(IssueComment::query()->count())->toBe(1);
});

test('github sync matches remote assignees through user identities', function () {
    Http::preventStrayRequests();

    ['source' => $source, 'owner' => $owner] = githubConnectionForOwner();

    UserIdentity::factory()->create([
        'user_id' => $owner->id,
        'external_id' => 'octocat',
        'email' => $owner->email,
    ]);

    fakeGithubSyncRequests();

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    $issue = Issue::query()->first();

    expect($issue->assignees()->pluck('users.id')->all())->toContain($owner->id);
});

test('github webhook creates a new issue comment', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12' => Http::response(['body' => '<p>Issue html</p>']),
        'https://api.github.com/repos/acme/api/issues/comments/888' => Http::response(['body' => '<p>Nice catch</p>']),
    ]);

    ['connection' => $connection] = githubConnectionForOwner();

    $payload = [
        'action' => 'created',
        'issue' => githubIssuePayload(),
        'comment' => [
            'id' => 888,
            'body' => 'Nice catch',
            'user' => ['login' => 'octocat'],
        ],
        'repository' => ['full_name' => 'acme/api'],
    ];

    githubWebhook($connection, 'issue_comment', $payload)->assertNoContent();

    $comment = IssueComment::query()->first();

    expect(IssueComment::query()->count())->toBe(1);
    expect($comment)
        ->external_id->toBe('888')
        ->body->toBe('Nice catch')
        ->origin->toBe(CommentOrigin::Remote);
});

test('github webhook deletes a remote comment', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12' => Http::response(['body' => '<p>Issue html</p>']),
    ]);

    ['connection' => $connection, 'source' => $source] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'external_id' => '1001',
        'number' => 12,
    ]);

    $comment = IssueComment::factory()->create([
        'issue_id' => $issue->id,
        'external_id' => '777',
        'body' => 'Going away',
    ]);

    $payload = [
        'action' => 'deleted',
        'issue' => githubIssuePayload(),
        'comment' => [
            'id' => 777,
            'body' => 'Going away',
            'user' => ['login' => 'octocat'],
        ],
        'repository' => ['full_name' => 'acme/api'],
    ];

    githubWebhook($connection, 'issue_comment', $payload)->assertNoContent();

    expect(IssueComment::query()->whereKey($comment->id)->exists())->toBeFalse();
});

test('full refresh removes remote comments that disappeared and keeps unsynced local comments', function () {
    Http::preventStrayRequests();

    ['source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'external_id' => '1001',
        'number' => 12,
        'title' => 'Fix client login',
        'body' => 'Users cannot sign in.',
        'body_html' => '<p>Users cannot sign in.</p>',
    ]);

    IssueComment::factory()->create([
        'issue_id' => $issue->id,
        'external_id' => '555',
        'body' => 'Looks good',
        'origin' => CommentOrigin::Remote,
    ]);

    $localComment = IssueComment::factory()->local()->create([
        'issue_id' => $issue->id,
        'body' => 'Still drafting',
        'author_name' => 'Ada',
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12/comments*' => Http::response([]),
    ]);

    $remote = app(GithubIssueProvider::class)->mapIssue(githubIssuePayload());

    app(RefreshIssueFromRemote::class)->syncRemote($source, $remote);

    expect(IssueComment::query()->where('external_id', '555')->exists())->toBeFalse();
    expect(IssueComment::query()->whereKey($localComment->id)->exists())->toBeTrue();
});

test('sync comments keeps local origin for comments already pushed to the source', function () {
    Http::preventStrayRequests();

    ['source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'number' => 12,
    ]);

    IssueComment::factory()->create([
        'issue_id' => $issue->id,
        'external_id' => '888',
        'body' => 'Pushed from the hub.',
        'body_html' => '<p>Pushed from the hub.</p>',
        'origin' => CommentOrigin::Local,
        'synced_at' => now(),
    ]);

    app(SyncIssueFromRemote::class)->syncComments($issue, collect([
        new RemoteComment(
            externalId: '888',
            body: 'Pushed from the hub.',
            authorName: 'octocat',
        ),
    ]));

    expect(IssueComment::query()->where('external_id', '888')->first()->origin)->toBe(CommentOrigin::Local);
});
