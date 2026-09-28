<?php

use App\Enums\ProjectStatus;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('an owner can create a project and keep its repository when editing', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;

    $this->actingAs($owner);

    Livewire::test('pages::projects.index')
        ->set('name', 'Client site')
        ->set('description', 'Marketing site for the spring launch.')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDontSee('GitHub repository');

    $project = Project::query()->where('name', 'Client site')->first();

    expect($project)
        ->team_id->toBe($team->id)
        ->description->toBe('Marketing site for the spring launch.')
        ->connected_source_id->toBeNull()
        ->status->toBe(ProjectStatus::Open);

    $connection = Connection::factory()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
    ]);

    $source = $connection->sources()->create([
        'team_id' => $team->id,
        'external_id' => 'acme/site',
        'name' => 'acme/site',
    ]);

    $project->update([
        'connected_source_id' => $source->id,
    ]);

    Livewire::test('pages::projects.show', ['project' => $project->fresh()])
        ->assertSee('acme/site')
        ->assertSee('Tasks')
        ->assertDontSee('Save project')
        ->assertDontSeeLivewire('projects.integrations');

    Livewire::test('pages::projects.integrations', ['project' => $project->fresh()])
        ->assertSeeLivewire('projects.integrations')
        ->assertSee('acme/site');

    Livewire::test('pages::projects.edit', ['project' => $project->fresh()])
        ->set('name', 'Client site renamed')
        ->set('description', 'Updated scope.')
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggleStatus');

    expect($project->fresh())
        ->name->toBe('Client site renamed')
        ->description->toBe('Updated scope.')
        ->connected_source_id->toBe($source->id)
        ->status->toBe(ProjectStatus::Closed);
});

test('a member can view assigned projects but cannot create or delete them', function () {
    ['team' => $team] = githubConnectionForOwner();

    $member = attachTeamMember($team, User::factory()->create(['email' => 'member@example.com']));

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Keep me',
    ]);

    attachProjectMember($project, $member);

    $this->actingAs($member);

    Livewire::test('pages::projects.index')
        ->assertSee('Keep me')
        ->call('create')
        ->assertForbidden();

    Livewire::test('pages::projects.show', ['project' => $project])
        ->assertDontSeeLivewire('projects.integrations')
        ->assertDontSee('Save project');

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->assertForbidden();

    Livewire::test('pages::projects.integrations', ['project' => $project])
        ->assertForbidden();
});

test('deleting a project keeps its tasks and removes its connection', function () {
    ['owner' => $owner, 'team' => $team, 'project' => $project, 'connection' => $connection, 'source' => $source] = githubConnectionForOwner();

    $task = Issue::factory()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'connection_id' => $connection->id,
        'connected_source_id' => $source->id,
        'title' => 'Still here',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->call('delete')
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->find($project->id))->toBeNull()
        ->and(Connection::query()->find($connection->id))->toBeNull()
        ->and($task->fresh()->project_id)->toBeNull()
        ->and($task->fresh()->connection_id)->toBeNull()
        ->and($task->fresh()->connected_source_id)->toBeNull()
        ->and($task->fresh()->title)->toBe('Still here');
});
