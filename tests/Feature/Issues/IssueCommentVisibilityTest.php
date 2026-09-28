<?php

use App\Enums\CommentAudience;
use App\Enums\TeamRole;
use App\Jobs\PushCommentToSource;
use App\Models\Client;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('a client comment is visible only to the owner and the client', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'admin' => $admin, 'issue' => $issue] = privateCommentFixtures();

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('body', 'Please change the logo color')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertSee('Please change the logo color');

    $comment = IssueComment::query()->first();

    expect($comment)
        ->audience->toBe(CommentAudience::Client)
        ->shared_at->toBeNull()
        ->user_id->toBe($clientUser->id);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('Private');

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color')
        ->assertSee('No comments yet.');

    $this->actingAs($admin);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color')
        ->assertSee('No comments yet.');
});

test('an owner reply to the client stays private and is not pushed to the source', function () {
    Http::preventStrayRequests();
    Queue::fake();

    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'admin' => $admin, 'issue' => $issue] = privateCommentFixtures(linked: true);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('body', 'I will update the logo tomorrow')
        ->call('addComment', 'client')
        ->assertHasNoErrors()
        ->assertSee('I will update the logo tomorrow');

    $reply = IssueComment::query()->where('body', 'I will update the logo tomorrow')->first();

    expect($reply)
        ->audience->toBe(CommentAudience::Client)
        ->shared_at->toBeNull()
        ->external_id->toBeNull();

    Queue::assertNotPushed(PushCommentToSource::class);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('I will update the logo tomorrow')
        ->assertDontSee('Share with team');

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('I will update the logo tomorrow');

    $this->actingAs($admin);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('I will update the logo tomorrow');
});

test('the owner can share a client comment with the team and make it private again', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'admin' => $admin, 'issue' => $issue] = privateCommentFixtures();

    $comment = IssueComment::factory()->clientThread()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Share with team')
        ->call('shareComment', $comment->id)
        ->assertSee('Shared with team')
        ->assertSee('Please change the logo color');

    expect($comment->fresh()->shared_at)->not->toBeNull();

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('From the client');

    $this->actingAs($admin);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('From the client');

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('unshareComment', $comment->id)
        ->assertSee('Private')
        ->assertDontSee('Shared with team');

    expect($comment->fresh()->shared_at)->toBeNull();

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color');

    $this->actingAs($admin);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color');

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertDontSee('Shared with team');
});

test('employees and admins cannot share a client comment', function () {
    ['clientUser' => $clientUser, 'employee' => $employee, 'admin' => $admin, 'issue' => $issue] = privateCommentFixtures();

    $comment = IssueComment::factory()->clientThread()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('shareComment', $comment->id)
        ->assertForbidden();

    $this->actingAs($admin);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('shareComment', $comment->id)
        ->assertForbidden();

    expect($comment->fresh()->shared_at)->toBeNull();
});

test('a team note is visible to staff and hidden from the client', function () {
    Http::preventStrayRequests();
    Queue::fake();

    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'admin' => $admin, 'issue' => $issue] = privateCommentFixtures(linked: true);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('body', 'Waiting on the logo file from design')
        ->call('addComment', 'internal')
        ->assertHasNoErrors()
        ->assertSee('Waiting on the logo file from design')
        ->assertSee('Team note');

    $note = IssueComment::query()->where('body', 'Waiting on the logo file from design')->first();

    expect($note->audience)->toBe(CommentAudience::Internal);

    Queue::assertPushed(PushCommentToSource::class, fn (PushCommentToSource $job): bool => $job->commentId === $note->id);

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Waiting on the logo file from design')
        ->assertDontSee('From the client');

    $this->actingAs($admin);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Waiting on the logo file from design');

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Waiting on the logo file from design')
        ->assertSee('No comments yet.');
});

test('a client cannot post a team note by choosing an internal audience', function () {
    ['clientUser' => $clientUser, 'employee' => $employee, 'issue' => $issue] = privateCommentFixtures();

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('body', 'This should stay on the client thread')
        ->call('addComment', 'internal')
        ->assertHasNoErrors();

    expect(IssueComment::query()->first())
        ->audience->toBe(CommentAudience::Client)
        ->shared_at->toBeNull();

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('This should stay on the client thread');
});

test('an employee cannot reply to the client by choosing a client audience', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'issue' => $issue] = privateCommentFixtures();

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Reply to client')
        ->assertSee('Note for team')
        ->set('body', 'Employees should not reach the client')
        ->call('addComment', 'client')
        ->assertHasNoErrors();

    expect(IssueComment::query()->first())
        ->audience->toBe(CommentAudience::Internal)
        ->user_id->toBe($employee->id);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Employees should not reach the client');

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Employees should not reach the client')
        ->assertSee('Team note');
});

test('employees and admins do not see reply to client', function () {
    ['clientUser' => $clientUser, 'employee' => $employee, 'admin' => $admin, 'issue' => $issue] = privateCommentFixtures();

    $clientComment = IssueComment::factory()->clientThread()->sharedWithTeam()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    foreach ([$employee, $admin] as $staff) {
        $this->actingAs($staff);

        Livewire::test('pages::issues.show', ['issue' => $issue])
            ->assertDontSee('Reply to client')
            ->assertSee('Note for team')
            ->call('startReply', $clientComment->id)
            ->assertDontSee('Reply to client')
            ->assertSee('Note for team');
    }
});

test('an owner reply is nested under the client comment and stays private', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'issue' => $issue] = privateCommentFixtures();

    $clientComment = IssueComment::factory()->clientThread()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('startReply', $clientComment->id)
        ->assertSee('Replying to '.$clientUser->name)
        ->set('body', 'I will update the logo tomorrow')
        ->call('addComment', 'client')
        ->assertHasNoErrors()
        ->assertSee('I will update the logo tomorrow');

    $reply = IssueComment::query()->where('body', 'I will update the logo tomorrow')->first();

    expect($reply)
        ->parent_id->toBe($clientComment->id)
        ->audience->toBe(CommentAudience::Client)
        ->shared_at->toBeNull();

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('I will update the logo tomorrow');

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color')
        ->assertDontSee('I will update the logo tomorrow');
});

test('an employee reply under a shared client comment stays a team note', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'issue' => $issue] = privateCommentFixtures();

    $clientComment = IssueComment::factory()->clientThread()->sharedWithTeam()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('startReply', $clientComment->id)
        ->assertDontSee('Reply to client')
        ->set('body', 'We can swap the logo next week')
        ->call('addComment', 'client')
        ->assertHasNoErrors();

    $reply = IssueComment::query()->where('body', 'We can swap the logo next week')->first();

    expect($reply)
        ->parent_id->toBe($clientComment->id)
        ->audience->toBe(CommentAudience::Internal)
        ->user_id->toBe($employee->id);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertDontSee('We can swap the logo next week');

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('We can swap the logo next week')
        ->assertSee('Team note');
});

test('a reply under a team note cannot be sent to the client', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'issue' => $issue] = privateCommentFixtures();

    $teamNote = IssueComment::factory()->local()->create([
        'issue_id' => $issue->id,
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'Waiting on the font file',
        'audience' => CommentAudience::Internal,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('startReply', $teamNote->id)
        ->assertDontSee('Reply to client')
        ->set('body', 'The font arrived this morning')
        ->call('addComment', 'client')
        ->assertHasErrors('body');

    expect(IssueComment::query()->where('body', 'The font arrived this morning')->exists())->toBeFalse();

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Waiting on the font file')
        ->assertDontSee('The font arrived this morning')
        ->assertDontSee('Note for team')
        ->assertDontSee('Team note')
        ->assertSee('No comments yet.');
});

test('a team note cannot be nested under a private client comment', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'issue' => $issue] = privateCommentFixtures();

    $clientComment = IssueComment::factory()->clientThread()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('startReply', $clientComment->id)
        ->assertDontSee('Note for team')
        ->assertSee('Share this comment with the team before leaving a team note.')
        ->set('body', 'We can swap the logo next week')
        ->call('addComment', 'internal')
        ->assertHasErrors('body');

    expect(IssueComment::query()->where('body', 'We can swap the logo next week')->exists())->toBeFalse();

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('shareComment', $clientComment->id)
        ->call('startReply', $clientComment->id)
        ->set('body', 'We can swap the logo next week')
        ->call('addComment', 'internal')
        ->assertHasNoErrors();

    expect(IssueComment::query()->where('body', 'We can swap the logo next week')->first())
        ->parent_id->toBe($clientComment->id)
        ->audience->toBe(CommentAudience::Internal);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertDontSee('We can swap the logo next week')
        ->assertDontSee('Note for team')
        ->assertDontSee('Team note');
});

test('a reply to a reply is nested and indented under that reply', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'issue' => $issue] = privateCommentFixtures();

    $clientComment = IssueComment::factory()->clientThread()->sharedWithTeam()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $reply = IssueComment::factory()->clientThread()->sharedWithTeam()->create([
        'issue_id' => $issue->id,
        'parent_id' => $clientComment->id,
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'I will update the logo tomorrow',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('startReply', $reply->id)
        ->assertSee('Replying to '.$owner->name)
        ->set('body', 'The new logo is attached')
        ->call('addComment', 'client')
        ->assertHasNoErrors();

    $nested = IssueComment::query()->where('body', 'The new logo is attached')->first();

    expect($nested)
        ->parent_id->toBe($reply->id)
        ->audience->toBe(CommentAudience::Client);

    $html = Livewire::test('pages::issues.show', ['issue' => $issue])->html();

    expect(substr_count($html, 'data-test="comment-replies"'))->toBe(2)
        ->and(strpos($html, 'Please change the logo color'))->toBeLessThan(strpos($html, 'I will update the logo tomorrow'))
        ->and(strpos($html, 'I will update the logo tomorrow'))->toBeLessThan(strpos($html, 'The new logo is attached'));

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('I will update the logo tomorrow')
        ->assertSee('The new logo is attached');

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('unshareComment', $clientComment->id);

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color')
        ->assertDontSee('I will update the logo tomorrow')
        ->assertDontSee('The new logo is attached');
});

test('hiding a client comment hides the team replies nested under it', function () {
    ['owner' => $owner, 'clientUser' => $clientUser, 'employee' => $employee, 'issue' => $issue] = privateCommentFixtures();

    $clientComment = IssueComment::factory()->clientThread()->sharedWithTeam()->create([
        'issue_id' => $issue->id,
        'user_id' => $clientUser->id,
        'author_name' => $clientUser->name,
        'body' => 'Please change the logo color',
    ]);

    $teamReply = IssueComment::factory()->local()->create([
        'issue_id' => $issue->id,
        'parent_id' => $clientComment->id,
        'user_id' => $employee->id,
        'author_name' => $employee->name,
        'body' => 'We can swap the logo next week',
        'audience' => CommentAudience::Internal,
    ]);

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('We can swap the logo next week');

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('unshareComment', $clientComment->id)
        ->assertHasNoErrors();

    expect($clientComment->fresh()->shared_at)->toBeNull()
        ->and($teamReply->fresh()->parent_id)->toBe($clientComment->id);

    $this->actingAs($employee);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertDontSee('Please change the logo color')
        ->assertDontSee('We can swap the logo next week')
        ->assertSee('No comments yet.');

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Please change the logo color')
        ->assertSee('We can swap the logo next week');
});

/**
 * @return array{owner: User, team: Team, clientUser: User, employee: User, admin: User, issue: Issue}
 */
function privateCommentFixtures(bool $linked = false): array
{
    if ($linked) {
        ['owner' => $owner, 'team' => $team, 'project' => $project, 'connection' => $connection, 'source' => $source] = githubConnectionForOwner();

        $client = Client::factory()->create([
            'team_id' => $team->id,
            'name' => 'Acme Corp',
        ]);

        $project->update(['client_id' => $client->id]);

        $issue = Issue::factory()->create([
            'connected_source_id' => $source->id,
            'connection_id' => $connection->id,
            'team_id' => $team->id,
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Update the homepage logo',
            'number' => 12,
            'last_synced_at' => now(),
        ]);
    } else {
        ['owner' => $owner, 'team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures();

        $issue = Issue::factory()->local()->create([
            'team_id' => $team->id,
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Update the homepage logo',
        ]);
    }

    $clientUser = User::factory()->create([
        'name' => 'Acme Client',
        'email' => 'client@acme.test',
    ]);
    attachClientUser($team, $client, $clientUser);

    $employee = User::factory()->create([
        'name' => 'Riley Employee',
        'email' => 'employee@example.com',
    ]);
    attachTeamMember($team, $employee);
    attachProjectMember($project, $employee);

    $admin = User::factory()->create([
        'name' => 'Avery Admin',
        'email' => 'admin@example.com',
    ]);
    attachTeamMember($team, $admin, TeamRole::Admin);

    return compact('owner', 'team', 'clientUser', 'employee', 'admin', 'issue');
}
