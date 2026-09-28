<?php

use App\Jobs\PushCommentToSource;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('commenting on an issue queues a push to the source', function () {
    Queue::fake();

    ['owner' => $owner, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'title' => 'Fix client login',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('body', 'Working on this now.')
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = IssueComment::query()->first();

    expect($comment)
        ->body->toBe('Working on this now.')
        ->author_name->toBe($owner->name)
        ->external_id->toBeNull();

    Queue::assertPushed(PushCommentToSource::class, fn (PushCommentToSource $job): bool => $job->commentId === $comment->id);
});

test('push comment job posts to github and stores the remote id', function () {
    Http::preventStrayRequests();

    ['source' => $source, 'connection' => $connection, 'owner' => $owner] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'number' => 12,
    ]);

    $comment = IssueComment::factory()->local()->create([
        'issue_id' => $issue->id,
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'Pushed from the hub.',
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12/comments' => Http::response([
            'id' => 888,
            'body' => 'Pushed from the hub.',
            'user' => ['login' => 'octocat'],
        ], 201),
    ]);

    (new PushCommentToSource($comment->id))->handle(
        app(IssueProviderFactory::class),
    );

    $comment->refresh();

    expect($comment->external_id)->toBe('888');
    expect($comment->synced_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/repos/acme/api/issues/12/comments'
        && $request['body'] === 'Pushed from the hub.');
});

test('push comment job does not post again when the comment already has an external id', function () {
    Http::preventStrayRequests();
    Http::fake();

    ['source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
    ]);

    $comment = IssueComment::factory()->create([
        'issue_id' => $issue->id,
        'external_id' => '999',
    ]);

    (new PushCommentToSource($comment->id))->handle(
        app(IssueProviderFactory::class),
    );

    Http::assertNothingSent();
});
