<?php

use App\Models\Client;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('a client assigns the owner and people on their projects', function () {
    $owner = User::factory()->create([
        'name' => 'Avery Owner',
        'email' => 'owner@example.com',
    ]);

    ['team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures($owner);

    $projectMember = attachTeamMember($team, User::factory()->create([
        'name' => 'Riley Employee',
        'email' => 'riley@example.com',
    ]));
    attachProjectMember($project, $projectMember);

    $otherEmployee = attachTeamMember($team, User::factory()->create([
        'name' => 'Jordan Employee',
        'email' => 'jordan@example.com',
    ]));

    $clientUser = User::factory()->create([
        'name' => 'Casey Client',
        'email' => 'casey@example.com',
    ]);
    attachClientUser($team, $client, $clientUser);

    $label = Label::factory()->create([
        'team_id' => $team->id,
        'name' => 'Invoice tag',
    ]);

    $task = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Client visible task',
    ]);
    $task->labels()->attach($label);

    $this->actingAs($clientUser);

    $this->get(route('client.issues.index', ['current_team' => $client->slug]))
        ->assertOk()
        ->assertSee('Users')
        ->assertSee('Avery Owner')
        ->assertSee('Riley Employee')
        ->assertDontSee('Jordan Employee')
        ->assertDontSee('Invoice tag')
        ->assertDontSee('All labels')
        ->assertDontSee('Labels');

    $this->get(route('client.users.index', ['current_team' => $client->slug]))
        ->assertOk()
        ->assertSee('Avery Owner')
        ->assertSee('Riley Employee')
        ->assertDontSee('Jordan Employee')
        ->assertDontSee('Add user');

    $this->get(route('client.issues.show', ['current_team' => $client->slug, 'issue' => $task]))
        ->assertOk()
        ->assertDontSee('Invoice tag')
        ->assertDontSee('Labels');

    $this->get(route('client.issues.edit', ['current_team' => $client->slug, 'issue' => $task]))
        ->assertOk()
        ->assertSee('Avery Owner')
        ->assertSee('Riley Employee')
        ->assertDontSee('Jordan Employee')
        ->assertDontSee('Invoice tag')
        ->assertDontSee('Save labels');

    $this->get(route('client.labels.index', ['current_team' => $client->slug]))
        ->assertForbidden();

    Livewire::test('issues.task-form')
        ->assertSee('Avery Owner')
        ->assertSee('Riley Employee')
        ->assertDontSee('Jordan Employee')
        ->assertDontSee('Invoice tag')
        ->set('projectId', $project->id)
        ->set('title', 'Scoped task')
        ->set('assigneeIds', [$owner->id])
        ->set('labelNames', ['Invoice tag'])
        ->call('create')
        ->assertHasNoErrors();

    $created = Issue::query()->where('title', 'Scoped task')->first();

    expect($created)->not->toBeNull()
        ->and($created->assignees()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($created->labels()->count())->toBe(0);

    Livewire::test('pages::issues.edit', ['issue' => $task])
        ->set('assigneeIds', [$projectMember->id])
        ->call('saveAssignees')
        ->assertHasNoErrors();

    expect($task->fresh()->assignees()->pluck('users.id')->all())->toBe([$projectMember->id]);

    Livewire::test('pages::issues.edit', ['issue' => $task])
        ->set('assigneeIds', [$otherEmployee->id])
        ->call('saveAssignees')
        ->assertHasErrors('assigneeIds');

    expect($task->fresh()->assignees()->pluck('users.id')->all())->toBe([$projectMember->id]);

    Livewire::test('pages::issues.edit', ['issue' => $task])
        ->call('saveLabels')
        ->assertForbidden();

    Livewire::test('pages::projects.show', ['project' => $project])
        ->call('assignTask', $task->id, (string) $owner->id)
        ->assertHasNoErrors();

    expect($task->fresh()->assignees()->pluck('users.id')->all())->toBe([$owner->id]);
});

test('the team owner is an assignee for every client even with no project members', function () {
    $owner = User::factory()->create([
        'name' => 'Avery Owner',
        'email' => 'owner@example.com',
    ]);

    ['team' => $team, 'client' => $firstClient, 'assignedProject' => $firstProject] = clientPortalFixtures($owner);

    $secondClient = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Northwind',
    ]);

    $secondProject = Project::factory()->create([
        'team_id' => $team->id,
        'client_id' => $secondClient->id,
        'name' => 'Northwind site',
    ]);

    $firstUser = attachClientUser($team, $firstClient, User::factory()->create([
        'name' => 'Casey Client',
        'email' => 'casey@example.com',
    ]));

    $secondUser = attachClientUser($team, $secondClient, User::factory()->create([
        'name' => 'Pat Client',
        'email' => 'pat@example.com',
    ]));

    $this->actingAs($firstUser);

    Livewire::test('issues.task-form')
        ->assertSee('Avery Owner')
        ->set('projectId', $firstProject->id)
        ->set('title', 'First client task')
        ->set('assigneeIds', [$owner->id])
        ->call('create')
        ->assertHasNoErrors();

    expect(Issue::query()->where('title', 'First client task')->first()?->assignees()->pluck('users.id')->all())->toBe([$owner->id]);

    $this->actingAs($secondUser);

    Livewire::test('issues.task-form')
        ->assertSee('Avery Owner')
        ->set('projectId', $secondProject->id)
        ->set('title', 'Second client task')
        ->set('assigneeIds', [$owner->id])
        ->call('create')
        ->assertHasNoErrors();

    expect(Issue::query()->where('title', 'Second client task')->first()?->assignees()->pluck('users.id')->all())->toBe([$owner->id]);
});
