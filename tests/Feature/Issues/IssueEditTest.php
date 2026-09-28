<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('a project member who did not create the task can assign people from the edit page', function () {
    ['owner' => $owner, 'team' => $team] = githubConnectionForOwner();

    $member = attachTeamMember($team, User::factory()->create([
        'name' => 'Avery Member',
        'email' => 'member@example.com',
    ]));

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
    ]);

    attachProjectMember($project, $member);

    $task = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'title' => 'Owner task',
    ]);

    $this->actingAs($member);

    Livewire::test('pages::issues.show', ['issue' => $task])
        ->assertSee('Owner task')
        ->assertSee('Edit')
        ->assertDontSee('Save task');

    Livewire::test('pages::issues.edit', ['issue' => $task])
        ->assertSee('Save assignees')
        ->assertDontSee('Save task')
        ->call('saveDetails')
        ->assertForbidden();
});

test('a client edits a task on their project from the edit page', function () {
    ['team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $task = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Client visible task',
        'body' => 'Please review the homepage.',
    ]);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.show', ['issue' => $task])
        ->assertSee('Client visible task')
        ->assertSee('Please review the homepage.')
        ->assertSee('Edit')
        ->assertDontSee('Save task');

    $this->get(route('client.issues.edit', ['current_team' => $client->slug, 'issue' => $task]))
        ->assertOk()
        ->assertSee('Save task')
        ->assertSee('Save assignees')
        ->assertDontSee('Labels');
});
