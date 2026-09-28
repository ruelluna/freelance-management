<?php

use App\Models\User;
use Livewire\Livewire;

test('admin can create a label from a selected color', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::labels.index')
        ->assertSee('label-color-wheel')
        ->set('name', 'Website')
        ->set('color', '0E8A16')
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('labels', [
        'team_id' => $owner->currentTeam->id,
        'name' => 'Website',
        'color' => '0e8a16',
    ]);
});

test('label color must be a six character hex value', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::labels.index')
        ->set('name', 'Website')
        ->set('color', 'green')
        ->call('create')
        ->assertHasErrors(['color']);

    $this->assertDatabaseMissing('labels', [
        'name' => 'Website',
    ]);
});
