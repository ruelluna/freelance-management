<?php

use App\Actions\Projects\AssignProjectMembers;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('members with no assignments see no projects', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = $owner->currentTeam;

    attachTeamMember($team, $member);

    Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Hidden project',
    ]);

    $this->actingAs($member);

    Livewire::test('pages::projects.index')
        ->assertDontSee('Hidden project');
});

test('members assigned to a project see all its issues', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = $owner->currentTeam;

    attachTeamMember($team, $member);

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Assigned project',
    ]);

    app(AssignProjectMembers::class)->handle($project, [$member->id]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Visible assigned task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => Project::factory()->create(['team_id' => $team->id])->id,
        'title' => 'Hidden other project task',
    ]);

    $this->actingAs($member);

    Livewire::test('pages::issues.index')
        ->assertSee('Visible assigned task')
        ->assertDontSee('Hidden other project task');
});

test('members can see individually assigned issues without project access', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = $owner->currentTeam;

    attachTeamMember($team, $member);

    $internalProject = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Internal project',
    ]);

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $internalProject->id,
        'title' => 'Single shared task',
    ]);

    $issue->assignees()->attach($member);

    $this->actingAs($member);

    Livewire::test('pages::issues.index')
        ->assertSee('Single shared task');

    Livewire::test('pages::projects.show', ['project' => $internalProject])
        ->assertForbidden();
});

test('members can see issues they created even without project assignment', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = $owner->currentTeam;

    attachTeamMember($team, $member);

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Temporary project',
    ]);

    app(AssignProjectMembers::class)->handle($project, [$member->id]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'created_by' => $member->id,
        'title' => 'Created by member',
    ]);

    app(AssignProjectMembers::class)->handle($project, []);

    $this->actingAs($member);

    Livewire::test('pages::issues.index')
        ->assertSee('Created by member');
});

test('admin can assign members to a project from the project page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = $owner->currentTeam;

    attachTeamMember($team, $member);

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Member project',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('memberIds', [$member->id])
        ->call('saveMembers')
        ->assertHasNoErrors();

    expect($project->fresh()->members->pluck('id')->all())->toBe([$member->id]);
});
