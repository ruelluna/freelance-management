<?php

use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\User;

test('an admin can view the app as a client', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $admin = attachTeamMember($team, User::factory()->create([
        'name' => 'Alex Admin',
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);
    $client = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme Corp',
    ]);
    $clientUser = attachClientUser($team, $client, User::factory()->create([
        'name' => 'Casey Client',
        'email' => 'casey@example.com',
    ]));

    $this->actingAs($admin)
        ->get(route('impersonate', $clientUser))
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($clientUser);

    $this->get(route('home'))
        ->assertRedirect(route('dashboard', ['current_team' => $clientUser->portalSlug()]));

    $this->get(route('dashboard', ['current_team' => $clientUser->portalSlug()]))
        ->assertOk()
        ->assertSee('Viewing as '.$clientUser->name);
});

test('a member cannot start a preview', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $member = attachTeamMember($team, User::factory()->create([
        'email' => 'member@example.com',
    ]), TeamRole::Member);
    $client = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme Corp',
    ]);
    $clientUser = attachClientUser($team, $client, User::factory()->create([
        'email' => 'casey@example.com',
    ]));

    $this->actingAs($member)
        ->get(route('impersonate', $clientUser))
        ->assertForbidden();

    $this->assertAuthenticatedAs($member);
});

test('a user outside the current team cannot be previewed', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $admin = attachTeamMember($owner->currentTeam, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);
    $outsider = User::factory()->create(['email' => 'outsider@example.com']);

    $this->actingAs($admin)
        ->from(route('users.index'))
        ->get(route('impersonate', $outsider))
        ->assertRedirect(route('users.index'));

    $this->assertAuthenticatedAs($admin);
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

test('leaving a preview restores the original user', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $admin = attachTeamMember($team, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);
    $client = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme Corp',
    ]);
    $clientUser = attachClientUser($team, $client, User::factory()->create([
        'email' => 'casey@example.com',
    ]));

    $this->actingAs($admin)->get(route('impersonate', $clientUser));

    $this->get(route('impersonate.leave'))
        ->assertRedirect(route('users.index'));

    $this->assertAuthenticatedAs($admin);
    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

test('security settings are blocked during a preview', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = $owner->currentTeam;
    $admin = attachTeamMember($team, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);
    $client = Client::factory()->create([
        'team_id' => $team->id,
        'name' => 'Acme Corp',
    ]);
    $clientUser = attachClientUser($team, $client, User::factory()->create([
        'email' => 'casey@example.com',
    ]));

    $this->actingAs($admin)->get(route('impersonate', $clientUser));

    $this->from(route('home'))
        ->get(route('security.edit'))
        ->assertRedirect(route('home'));
});
