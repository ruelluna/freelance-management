<?php

use App\Models\Issue;
use App\Models\User;
use Livewire\Livewire;

test('admin can filter tasks by client on the tasks page', function () {
    ['owner' => $owner, 'client' => $client, 'assignedProject' => $assignedProject, 'internalProject' => $internalProject] = clientPortalFixtures();

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $assignedProject->id,
        'title' => 'Client scoped task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $internalProject->id,
        'title' => 'Internal only task',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->set('clientId', $client->id)
        ->assertSee('Client scoped task')
        ->assertDontSee('Internal only task');
});

test('admin can filter tasks by internal client scope', function () {
    ['owner' => $owner, 'assignedProject' => $assignedProject, 'internalProject' => $internalProject] = clientPortalFixtures();

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $assignedProject->id,
        'title' => 'Client scoped task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $internalProject->id,
        'title' => 'Internal only task',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->set('clientId', 'internal')
        ->assertSee('Internal only task')
        ->assertDontSee('Client scoped task');
});

test('admin dashboard shows filterable issue list', function () {
    ['owner' => $owner, 'client' => $client, 'assignedProject' => $assignedProject, 'internalProject' => $internalProject] = clientPortalFixtures();

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $assignedProject->id,
        'title' => 'Dashboard client task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $owner->currentTeam->id,
        'project_id' => $internalProject->id,
        'title' => 'Dashboard internal task',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::workspace')
        ->set('clientId', $client->id)
        ->assertSee('Dashboard client task')
        ->assertDontSee('Dashboard internal task');
});

test('client filter is hidden for client users', function () {
    ['team' => $team, 'client' => $client] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client-filter@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.index')
        ->assertDontSeeHtml('data-test="issue-client-filter"');
});
