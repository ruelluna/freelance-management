<?php

use App\Models\User;

test('guests visiting home are redirected to login', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

test('authenticated staff visiting home are redirected to the workspace', function () {
    $staff = User::factory()->create(['email' => 'staff@example.com']);

    $this->actingAs($staff)
        ->get(route('home'))
        ->assertRedirect(route('workspace'));
});

test('authenticated clients visiting home are redirected to their dashboard', function () {
    ['team' => $team, 'client' => $client] = clientPortalFixtures();
    $clientUser = attachClientUser($team, $client, User::factory()->create([
        'email' => 'client@acme.test',
    ]));

    $this->actingAs($clientUser)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
