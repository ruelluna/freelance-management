<?php

use App\Actions\Clients\InviteClientUser;
use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('admin can create a client', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner);

    Livewire::test('pages::clients.index')
        ->set('name', 'Acme Corp')
        ->set('contactEmail', 'hello@acme.test')
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('clients', [
        'team_id' => $owner->currentTeam->id,
        'name' => 'Acme Corp',
        'contact_email' => 'hello@acme.test',
    ]);
});

test('admin can assign a project to a client', function () {
    ['owner' => $owner, 'client' => $client, 'internalProject' => $project] = clientPortalFixtures();

    $this->actingAs($owner);

    Livewire::test('pages::clients.show', ['client' => $client])
        ->set('assignProjectId', $project->id)
        ->call('assignProject')
        ->assertHasNoErrors();

    expect($project->fresh()->client_id)->toBe($client->id);
});

test('client users only see assigned projects', function () {
    ['team' => $team, 'client' => $client, 'assignedProject' => $assignedProject, 'internalProject' => $internalProject] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::projects.index')
        ->assertSee('Acme website')
        ->assertDontSee('Internal ops');
});

test('client users receive 403 when visiting an internal project directly', function () {
    ['team' => $team, 'client' => $client, 'internalProject' => $internalProject] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::projects.show', ['project' => $internalProject])
        ->assertForbidden();
});

test('client users only see issues from assigned projects', function () {
    ['team' => $team, 'client' => $client, 'assignedProject' => $assignedProject, 'internalProject' => $internalProject] = clientPortalFixtures();

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $assignedProject->id,
        'title' => 'Visible client task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $internalProject->id,
        'title' => 'Hidden internal task',
    ]);

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::issues.index')
        ->assertSee('Visible client task')
        ->assertDontSee('Hidden internal task');
});

test('client invitation acceptance links the user to the client', function () {
    Notification::fake();

    ['owner' => $owner, 'team' => $team, 'client' => $client] = clientPortalFixtures();
    $invitedUser = User::factory()->create(['email' => 'invited-client@acme.test']);

    $this->actingAs($owner);

    $invitation = app(InviteClientUser::class)->handle($client, 'invited-client@acme.test');

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertRedirect(route('dashboard'));

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
    expect($invitedUser->fresh()->teamRole($team))->toBe(TeamRole::Client);
    expect($invitedUser->fresh()->clientForTeam($team)?->is($client))->toBeTrue();
});

test('unassigning a project hides it from client users immediately', function () {
    ['owner' => $owner, 'client' => $client, 'assignedProject' => $assignedProject] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($owner->currentTeam, $client, $clientUser);

    $this->actingAs($owner);

    Livewire::test('pages::clients.show', ['client' => $client])
        ->call('unassignProject', $assignedProject->id)
        ->assertHasNoErrors();

    $this->actingAs($clientUser);

    Livewire::test('pages::projects.index')
        ->assertDontSee('Acme website');
});

test('multiple users on the same client see the same assigned projects', function () {
    ['team' => $team, 'client' => $client, 'assignedProject' => $assignedProject] = clientPortalFixtures();

    $firstClientUser = User::factory()->create(['email' => 'client-one@acme.test']);
    $secondClientUser = User::factory()->create(['email' => 'client-two@acme.test']);

    attachClientUser($team, $client, $firstClientUser);
    attachClientUser($team, $client, $secondClientUser);

    $this->actingAs($secondClientUser);

    Livewire::test('pages::projects.index')
        ->assertSee('Acme website');
});

test('archived clients block new invitations', function () {
    ['owner' => $owner, 'client' => $client] = clientPortalFixtures();

    $client->update(['status' => 'archived']);

    $this->actingAs($owner);

    expect(fn () => app(InviteClientUser::class)->handle($client->fresh(), 'blocked@acme.test'))
        ->toThrow(ValidationException::class);
});

test('client users only see their own projects and issues', function () {
    ['team' => $team, 'client' => $client, 'assignedProject' => $assignedProject] = clientPortalFixtures();

    $otherClient = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Beta LLC',
    ]);

    $otherProject = Project::factory()->create([
        'team_id' => $team->id,
        'client_id' => $otherClient->id,
        'name' => 'Beta portal',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $assignedProject->id,
        'title' => 'Visible client task',
    ]);

    $otherIssue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $otherProject->id,
        'title' => 'Other client task',
    ]);

    Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => null,
        'title' => 'Inbox task',
    ]);

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::projects.index')
        ->assertSee('Acme website')
        ->assertDontSee('Beta portal')
        ->assertDontSee('Internal ops')
        ->assertDontSee('Beta LLC');

    Livewire::test('pages::issues.index')
        ->set('clientId', $otherClient->id)
        ->set('status', 'all')
        ->assertSee('Visible client task')
        ->assertDontSee('Other client task')
        ->assertDontSee('Inbox task')
        ->assertDontSee('Beta LLC');

    Livewire::test('pages::projects.show', ['project' => $assignedProject])
        ->assertSee('Visible client task')
        ->assertDontSee('Other client task');

    Livewire::test('pages::projects.show', ['project' => $otherProject])
        ->assertForbidden();

    Livewire::test('pages::issues.show', ['issue' => $otherIssue])
        ->assertForbidden();

    $this->get(route('client.projects.index', ['current_team' => $client->slug]))
        ->assertOk()
        ->assertSee('Acme website')
        ->assertDontSee('Beta portal');

    $this->get(route('client.issues.index', ['current_team' => $client->slug]))
        ->assertOk()
        ->assertSee('Visible client task')
        ->assertDontSee('Other client task')
        ->assertDontSee('Inbox task');
});

test('client users cannot access the clients admin page', function () {
    ['team' => $team, 'client' => $client] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($clientUser);

    Livewire::test('pages::clients.index')
        ->assertForbidden();
});
