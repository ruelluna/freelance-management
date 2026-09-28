<?php

use App\Enums\CommentOrigin;
use App\Models\Issue;
use App\Models\IssueComment;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('opening an issue page fetches new comments from github', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'external_id' => '1001',
        'number' => 12,
        'title' => 'Fix client login',
        'body' => 'Users cannot sign in.',
        'body_html' => '<p>Users cannot sign in.</p>',
        'last_synced_at' => now()->subMinutes(5),
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12/comments*' => Http::response([
            [
                'id' => 555,
                'body' => 'Looks good from GitHub',
                'user' => ['login' => 'octocat'],
                'created_at' => '2026-08-25T13:00:00Z',
            ],
        ]),
        'https://api.github.com/repos/acme/api/issues/comments/555' => Http::response([
            'body' => '<p>Looks good from GitHub</p>',
        ]),
        'https://api.github.com/repos/acme/api/issues/12' => Http::response(githubIssuePayload()),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Fix client login')
        ->assertSee('Looks good from GitHub')
        ->assertSee('Refresh');

    expect(IssueComment::query()->where('issue_id', $issue->id)->where('external_id', '555')->exists())->toBeTrue();
});

test('refresh button force fetches comments even when recently synced', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'external_id' => '1001',
        'number' => 12,
        'title' => 'Fix client login',
        'body' => 'Users cannot sign in.',
        'body_html' => '<p>Users cannot sign in.</p>',
        'last_synced_at' => now(),
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12/comments*' => Http::response([
            [
                'id' => 901,
                'body' => 'Picked up by refresh',
                'user' => ['login' => 'octocat'],
                'created_at' => '2026-08-26T07:00:00Z',
            ],
        ]),
        'https://api.github.com/repos/acme/api/issues/comments/901' => Http::response([
            'body' => '<p>Picked up by refresh</p>',
        ]),
        'https://api.github.com/repos/acme/api/issues/12' => Http::response(githubIssuePayload()),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Picked up by refresh')
        ->call('refreshFromRemote')
        ->assertSee('Picked up by refresh');

    expect(IssueComment::query()->where('external_id', '901')->exists())->toBeTrue();
});

test('opening an issue still renders cached data when github is unavailable', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $source->team_id,
        'external_id' => '1001',
        'number' => 12,
        'title' => 'Fix client login',
        'body' => 'Users cannot sign in.',
        'last_synced_at' => now()->subMinutes(5),
    ]);

    IssueComment::factory()->create([
        'issue_id' => $issue->id,
        'body' => 'Cached comment',
        'author_name' => 'Ada',
        'origin' => CommentOrigin::Remote,
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/api/issues/12' => Http::response('Server error', 500),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Fix client login')
        ->assertSee('Cached comment');

    expect($issue->fresh()->last_synced_at->timestamp)->toBe($issue->last_synced_at->timestamp);
});
