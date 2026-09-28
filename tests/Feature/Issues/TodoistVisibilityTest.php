<?php

use App\Enums\IssueStatus;
use App\Enums\Provider;
use App\Enums\TeamRole;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('other admins cannot see an unassigned todoist task', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $admin = attachTeamMember($team, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'project_id' => null,
        'provider' => Provider::Todoist,
        'token' => 'todoist-token-value-123456',
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'all',
        'name' => 'All tasks',
    ]);

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'project_id' => null,
        'created_by' => $owner->id,
        'external_id' => 'task-1',
        'number' => null,
        'title' => 'Private milk run',
        'status' => IssueStatus::Open,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->assertSee('Private milk run');

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Private milk run');

    $this->actingAs($admin);

    Livewire::test('pages::issues.index')
        ->assertDontSee('Private milk run');

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertForbidden();
});

test('an assignee can see a todoist task that has no project', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $member = attachTeamMember($team, User::factory()->create([
        'email' => 'member@example.com',
    ]));

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'project_id' => null,
        'provider' => Provider::Todoist,
        'token' => 'todoist-token-value-123456',
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'all',
        'name' => 'All tasks',
    ]);

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'project_id' => null,
        'created_by' => $owner->id,
        'external_id' => 'task-1',
        'number' => null,
        'title' => 'Shared milk run',
        'status' => IssueStatus::Open,
        'last_synced_at' => now(),
    ]);

    $issue->assignees()->attach($member);

    $this->actingAs($member);

    Livewire::test('pages::issues.index')
        ->assertSee('Shared milk run');

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Shared milk run');

    $admin = attachTeamMember($team, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);

    $this->actingAs($admin);

    Livewire::test('pages::issues.index')
        ->assertDontSee('Shared milk run');
});

test('assigning a project lets other admins see the todoist task', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $admin = attachTeamMember($team, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'project_id' => null,
        'provider' => Provider::Todoist,
        'token' => 'todoist-token-value-123456',
    ]);

    $source = ConnectedSource::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'external_id' => 'all',
        'name' => 'All tasks',
    ]);

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
    ]);

    $issue = Issue::factory()->create([
        'connected_source_id' => $source->id,
        'connection_id' => $connection->id,
        'team_id' => $team->id,
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'external_id' => 'task-1',
        'number' => null,
        'title' => 'Project milk run',
        'status' => IssueStatus::Open,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::issues.index')
        ->assertSee('Project milk run');

    Livewire::test('pages::issues.show', ['issue' => $issue])
        ->assertSee('Project milk run');
});
