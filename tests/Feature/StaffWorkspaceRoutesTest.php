<?php

use App\Models\Issue;
use App\Models\User;

test('staff open workspace sections without a team prefix', function () {
    ['owner' => $owner, 'team' => $team, 'assignedProject' => $assignedProject] = clientPortalFixtures();

    $member = User::factory()->create([
        'email' => 'worker@example.com',
    ]);

    attachTeamMember($team, $member);
    attachProjectMember($assignedProject, $member);

    $this->actingAs($owner)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Acme website')
        ->assertSee('Internal ops');

    $this->actingAs($owner)->get(route('issues.index'))->assertOk();
    $this->actingAs($owner)->get(route('clients.index'))->assertOk()->assertSee('Acme Corp');
    $this->actingAs($owner)->get(route('labels.index'))->assertOk();

    $this->actingAs($member)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Acme website')
        ->assertDontSee('Internal ops');
});

test('staff team section urls redirect to the workspace', function () {
    $owner = User::factory()->create();
    $slug = $owner->currentTeam->slug;

    $this->actingAs($owner)
        ->get(route('client.projects.index', ['current_team' => $slug]))
        ->assertRedirect('/projects');

    $this->actingAs($owner)
        ->get("/{$slug}/issues?status=closed")
        ->assertRedirect('/issues?status=closed');

    $this->actingAs($owner)
        ->get("/{$slug}/clients")
        ->assertRedirect('/clients');

    $this->actingAs($owner)
        ->get("/{$slug}/labels")
        ->assertRedirect('/labels');
});

test('clients are sent from workspace sections to their team urls', function () {
    ['team' => $team, 'client' => $client, 'assignedProject' => $assignedProject] = clientPortalFixtures();

    $clientUser = User::factory()->create([
        'email' => 'client@acme.test',
    ]);

    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser)
        ->get('/projects')
        ->assertRedirect("/{$client->slug}/projects");

    $this->actingAs($clientUser)
        ->get(route('client.projects.index', ['current_team' => $team->slug]))
        ->assertRedirect("/{$client->slug}/projects");

    $this->actingAs($clientUser)
        ->get(route('client.projects.index', ['current_team' => $client->slug]))
        ->assertOk()
        ->assertSee('Acme website')
        ->assertDontSee('Internal ops');

    $this->actingAs($clientUser)
        ->get('/issues')
        ->assertRedirect("/{$client->slug}/issues");

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $assignedProject->id,
        'title' => 'Visible client task',
    ]);

    $this->actingAs($clientUser)
        ->get(route('client.issues.index', ['current_team' => $client->slug]))
        ->assertOk()
        ->assertSee('Visible client task')
        ->assertDontSee('Internal ops');
});
