<?php

use App\Enums\ProjectStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('an owner can create a project linked to a github repo and then clear the link', function () {
    ['owner' => $owner, 'team' => $team, 'source' => $source] = githubConnectionForOwner();

    $this->actingAs($owner);

    Livewire::test('pages::projects.index')
        ->set('name', 'Client site')
        ->set('description', 'Marketing site for the spring launch.')
        ->set('connectedSourceId', $source->id)
        ->call('create')
        ->assertHasNoErrors();

    $project = Project::query()->where('name', 'Client site')->first();

    expect($project)
        ->team_id->toBe($team->id)
        ->description->toBe('Marketing site for the spring launch.')
        ->connected_source_id->toBe($source->id)
        ->status->toBe(ProjectStatus::Open);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->set('name', 'Client site renamed')
        ->set('description', 'Updated scope.')
        ->set('connectedSourceId', '')
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggleStatus');

    expect($project->fresh())
        ->name->toBe('Client site renamed')
        ->description->toBe('Updated scope.')
        ->connected_source_id->toBeNull()
        ->status->toBe(ProjectStatus::Closed);
});

test('a member can view projects but cannot create or delete them', function () {
    ['team' => $team] = githubConnectionForOwner();

    $member = attachTeamMember($team, User::factory()->create(['email' => 'member@example.com']));

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Keep me',
    ]);

    $this->actingAs($member);

    Livewire::test('pages::projects.index')
        ->assertSee('Keep me')
        ->call('create')
        ->assertForbidden();

    Livewire::test('pages::projects.show', ['project' => $project])
        ->call('delete')
        ->assertForbidden();
});

test('deleting a project keeps its tasks in the inbox', function () {
    ['owner' => $owner, 'team' => $team] = githubConnectionForOwner();

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Temporary',
    ]);

    $task = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Still here',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->call('delete')
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->find($project->id))->toBeNull()
        ->and($task->fresh()->project_id)->toBeNull()
        ->and($task->fresh()->title)->toBe('Still here');
});
