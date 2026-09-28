<?php

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Label;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('an owner can assign a team member to an issue', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/*' => Http::response([]),
    ]);

    ['owner' => $owner, 'team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'title' => 'Assign me',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('assigneeIds', [$developer->id])
        ->call('saveAssignees')
        ->assertHasNoErrors();

    expect($issue->fresh()->assignees()->pluck('users.id')->all())->toContain($developer->id);
});

test('a member can assign a team member to an issue', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/*' => Http::response([]),
    ]);

    ['team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
    ]);

    $this->actingAs($developer);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->set('assigneeIds', [$developer->id])
        ->call('saveAssignees')
        ->assertHasNoErrors();

    expect($issue->fresh()->assignees()->pluck('users.id')->all())->toContain($developer->id);
});

test('a member can update an issue assigned to them', function () {
    Http::fake([
        'https://api.github.com/repos/acme/api/issues/*' => Http::response([]),
    ]);

    ['team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'status' => IssueStatus::Open,
    ]);

    $issue->assignees()->attach($developer);

    $this->actingAs($developer);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('toggleStatus')
        ->assertHasNoErrors();

    expect($issue->fresh()->status)->toBe(IssueStatus::Closed);
});

test('a member cannot update an issue that is not assigned to them', function () {
    ['team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $developer = attachTeamMember($team, User::factory()->create(['email' => 'dev@example.com']));

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
    ]);

    $this->actingAs($developer);

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->call('toggleStatus')
        ->assertForbidden();
});

test('issues can be filtered by label', function () {
    ['owner' => $owner, 'team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $website = Label::factory()->create([
        'team_id' => $team->id,
        'name' => 'website',
    ]);

    $websiteIssue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'title' => 'Website login bug',
    ]);

    $otherIssue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'title' => 'Unrelated invoice task',
    ]);

    $websiteIssue->labels()->attach($website);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->set('labelId', (string) $website->id)
        ->assertSee('Website login bug')
        ->assertDontSee('Unrelated invoice task');
});
