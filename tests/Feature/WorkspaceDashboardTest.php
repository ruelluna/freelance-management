<?php

use App\Models\Client;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('owners can open the staff dashboard', function () {
    ['owner' => $owner, 'assignedProject' => $assignedProject, 'internalProject' => $internalProject] = clientPortalFixtures();

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $assignedProject->id,
        'title' => 'Client scoped task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $internalProject->id,
        'title' => 'Internal team task',
    ]);

    $this->actingAs($owner)
        ->get(route('workspace'))
        ->assertOk()
        ->assertSee('Client scoped task')
        ->assertSee('Internal team task');
});

test('members only see assigned tasks on the staff dashboard', function () {
    ['team' => $team, 'assignedProject' => $assignedProject] = clientPortalFixtures();

    $otherClient = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Other Co',
    ]);

    $otherProject = Project::factory()->create([
        'team_id' => $team->id,
        'client_id' => $otherClient->id,
        'name' => 'Other website',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $assignedProject->id,
        'title' => 'Assigned worker task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $otherProject->id,
        'title' => 'Other client task',
    ]);

    $member = User::factory()->create([
        'email' => 'worker@example.com',
    ]);

    attachTeamMember($team, $member);
    attachProjectMember($assignedProject, $member);

    $this->actingAs($member)
        ->get(route('workspace'))
        ->assertOk()
        ->assertSee('Assigned worker task')
        ->assertDontSee('Other client task')
        ->assertDontSeeHtml('data-test="dashboard-issue-client-filter"');
});

test('clients are redirected from the staff dashboard to their team dashboard', function () {
    ['team' => $team, 'client' => $client] = clientPortalFixtures();

    $clientUser = User::factory()->create([
        'email' => 'client@acme.test',
    ]);

    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser)
        ->get(route('workspace'))
        ->assertRedirect(route('dashboard', ['current_team' => $client->slug]));
});

test('owners are redirected from the client dashboard to the staff dashboard', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('dashboard', ['current_team' => $owner->currentTeam->slug]))
        ->assertRedirect(route('workspace'));
});

test('owners log in to the staff dashboard', function () {
    $owner = User::factory()->create([
        'email' => 'owner@example.com',
    ]);

    $this->post(route('login.store'), [
        'email' => 'owner@example.com',
        'password' => 'password',
    ])->assertRedirect(route('workspace', absolute: false));
});

test('clients log in to their team dashboard', function () {
    ['team' => $team, 'client' => $client] = clientPortalFixtures();

    $clientUser = User::factory()->create([
        'email' => 'client@acme.test',
    ]);

    attachClientUser($team, $client, $clientUser);

    $this->post(route('login.store'), [
        'email' => 'client@acme.test',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', ['current_team' => $client->slug], absolute: false));
});
