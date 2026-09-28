<?php

use App\Models\User;

test('the env bar is visible only to allowed users', function () {
    config([
        'envbar.enabled' => true,
        'envbar.disable_on_tests' => false,
        'envbar.environments' => [
            'testing' => 'green',
        ],
        'envbar.for_authenticated_users.enabled' => true,
        'envbar.for_authenticated_users.viewers' => ['owner@example.com'],
        'envbar.on_mobile' => true,
    ]);

    $owner = User::factory()->create([
        'email' => 'owner@example.com',
    ]);
    $otherUser = User::factory()->create([
        'email' => 'client@example.com',
    ]);

    $this->actingAs($owner)
        ->get(route('workspace'))
        ->assertSuccessful()
        ->assertSee('id="envbar"', false)
        ->assertDontSee('id="envbar-close"', false)
        ->assertSee('--envbar-offset', false);

    $this->actingAs($otherUser)
        ->get(route('workspace'))
        ->assertSuccessful()
        ->assertDontSee('id="envbar"', false);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertDontSee('id="envbar"', false);
});
