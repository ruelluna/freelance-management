<?php

use App\Enums\Provider;
use App\Models\Client;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\Project;
use Livewire\Livewire;

test('an owner can assign a client and project from the task table', function () {
    ['owner' => $owner, 'team' => $team, 'client' => $client, 'assignedProject' => $project, 'internalProject' => $internalProject] = clientPortalFixtures();

    $otherClient = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Northwind',
    ]);

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'title' => 'Needs a home',
        'created_by' => $owner->id,
    ]);

    $otherProject = Project::factory()->create([
        'name' => 'Elsewhere',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->assertSeeHtml('data-test="table-client"')
        ->assertSeeHtml('data-test="table-project"')
        ->assertSee('Manual')
        ->assertSee($owner->name)
        ->call('assignProject', $issue->id, $project->id)
        ->assertHasNoErrors();

    expect($issue->fresh())
        ->project_id->toBe($project->id)
        ->client_id->toBe($client->id);

    Livewire::test('pages::issues.index')
        ->call('assignClient', $issue->id, $otherClient->id)
        ->assertHasNoErrors();

    expect($issue->fresh())
        ->client_id->toBe($otherClient->id)
        ->project_id->toBeNull();

    Livewire::test('pages::issues.index')
        ->call('assignProject', $issue->id, $internalProject->id)
        ->assertHasNoErrors();

    expect($issue->fresh())
        ->project_id->toBe($internalProject->id)
        ->client_id->toBeNull();

    Livewire::test('pages::issues.index')
        ->call('assignProject', $issue->id, '')
        ->assertHasNoErrors()
        ->call('assignProject', $issue->id, $otherProject->id)
        ->assertHasErrors('projectId');

    expect($issue->fresh()->project_id)->toBeNull();
});

test('the task table labels todoist, coda, and manual sources', function () {
    $codaIssue = new Issue;
    $codaConnection = new Connection;
    $codaConnection->provider = Provider::Superhuman;
    $codaIssue->setRelation('connection', $codaConnection);

    expect($codaIssue->sourceLabel())->toBe('Coda');

    ['owner' => $owner, 'team' => $team] = clientPortalFixtures();

    $todoist = Connection::factory()->create([
        'team_id' => $team->id,
        'provider' => Provider::Todoist,
        'name' => 'Todoist',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'title' => 'Typed here',
        'created_by' => $owner->id,
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'connection_id' => $todoist->id,
        'title' => 'From Todoist',
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::issues.index')
        ->assertSee('Todoist')
        ->assertSee('Manual')
        ->set('sourceId', 'manual')
        ->assertSee('Typed here')
        ->assertDontSee('From Todoist')
        ->set('sourceId', 'todoist')
        ->assertSee('From Todoist')
        ->assertDontSee('Typed here');
});
