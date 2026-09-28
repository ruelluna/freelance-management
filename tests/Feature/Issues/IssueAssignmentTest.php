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

    Livewire::test('pages::issues.edit', ['issue' => $issue])
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

    $issue->assignees()->attach($developer);

    $this->actingAs($developer);

    Livewire::test('pages::issues.edit', ['issue' => $issue])
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

    Livewire::test('pages::issues.edit', ['issue' => $issue])
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

    $this->get(route('issues.show', $issue))->assertForbidden();
    $this->get(route('issues.edit', $issue))->assertForbidden();
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

test('task assignees are limited to the owner and employees', function () {
    $owner = User::factory()->create([
        'name' => 'Avery Owner',
        'email' => 'owner@example.com',
    ]);

    ['team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures($owner);

    attachTeamMember($team, User::factory()->create([
        'name' => 'Riley Employee',
        'email' => 'riley@example.com',
    ]));

    $clientUser = User::factory()->create([
        'name' => 'Casey Client',
        'email' => 'casey@example.com',
    ]);

    attachClientUser($team, $client, $clientUser);

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Staff only task',
    ]);

    $this->actingAs($owner);

    Livewire::test('issues.task-form')
        ->assertSee('Avery Owner')
        ->assertSee('Riley Employee')
        ->assertDontSee('Casey Client')
        ->set('projectId', $project->id)
        ->set('title', 'Should stay unassigned')
        ->set('assigneeIds', [$clientUser->id])
        ->call('create')
        ->assertHasErrors('assigneeIds');

    expect(Issue::query()->where('title', 'Should stay unassigned')->exists())->toBeFalse();

    Livewire::test('pages::issues.edit', ['issue' => $issue])
        ->assertSee('Riley Employee')
        ->assertDontSee('Casey Client')
        ->set('assigneeIds', [$clientUser->id])
        ->call('saveAssignees')
        ->assertHasErrors('assigneeIds');

    expect($issue->fresh()->assignees()->pluck('users.id')->all())->toBe([]);

    Livewire::test('pages::issues.index')
        ->assertSee('Riley Employee')
        ->assertDontSee('Casey Client');
});

test('an owner can assign an employee from the task table', function () {
    $owner = User::factory()->create([
        'name' => 'Avery Owner',
        'email' => 'owner@example.com',
    ]);

    ['team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures($owner);

    $employee = attachTeamMember($team, User::factory()->create([
        'name' => 'Riley Employee',
        'email' => 'riley@example.com',
    ]));

    $clientUser = User::factory()->create([
        'name' => 'Casey Client',
        'email' => 'casey@example.com',
    ]);

    attachClientUser($team, $client, $clientUser);

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Table assign',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->assertSeeHtml('data-test="table-assignee"')
        ->call('assignTask', $issue->id, (string) $employee->id)
        ->assertHasNoErrors();

    expect($issue->fresh()->assignees()->pluck('users.id')->all())->toBe([$employee->id]);

    Livewire::test('pages::issues.index')
        ->call('assignTask', $issue->id, '')
        ->assertHasNoErrors();

    expect($issue->fresh()->assignees()->count())->toBe(0);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->call('assignTask', $issue->id, (string) $employee->id)
        ->assertHasNoErrors();

    expect($issue->fresh()->assignees()->pluck('users.id')->all())->toBe([$employee->id]);

    Livewire::test('pages::issues.index')
        ->call('assignTask', $issue->id, (string) $clientUser->id)
        ->assertHasErrors('assigneeIds');
});
