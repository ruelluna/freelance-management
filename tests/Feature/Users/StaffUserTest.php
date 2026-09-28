<?php

use App\Enums\TeamRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('an owner can create update and delete an employee', function () {
    $owner = User::factory()->create([
        'name' => 'Avery Owner',
        'email' => 'owner@example.com',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::users.index')
        ->set('name', 'Riley Employee')
        ->set('email', 'riley@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('role', TeamRole::Member->value)
        ->call('create')
        ->assertHasNoErrors();

    $employee = User::query()->where('email', 'riley@example.com')->first();

    expect($employee)->not->toBeNull()
        ->and($employee->teamRole($owner->currentTeam))->toBe(TeamRole::Member)
        ->and($employee->current_team_id)->toBe($owner->currentTeam->id)
        ->and($employee->email_verified_at)->not->toBeNull()
        ->and($employee->teams()->count())->toBe(1);

    Livewire::test('pages::users.show', ['user' => $employee])
        ->set('name', 'Riley Senior')
        ->set('email', 'riley.senior@example.com')
        ->set('role', TeamRole::Admin->value)
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->name)->toBe('Riley Senior')
        ->and($employee->email)->toBe('riley.senior@example.com')
        ->and($employee->teamRole($owner->currentTeam))->toBe(TeamRole::Admin)
        ->and(Hash::check('new-password', $employee->password))->toBeTrue();

    $passwordHash = $employee->password;

    Livewire::test('pages::users.show', ['user' => $employee])
        ->set('name', 'Riley Lead')
        ->set('password', null)
        ->set('password_confirmation', null)
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->name)->toBe('Riley Lead')
        ->and($employee->password)->toBe($passwordHash);

    Livewire::test('pages::users.show', ['user' => $employee])
        ->call('delete')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('users', [
        'id' => $employee->id,
    ]);
});

test('user role options come from the database', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::users.index')
        ->assertSeeHtml('value="admin"')
        ->assertSeeHtml('value="member"')
        ->assertDontSeeHtml('value="owner"')
        ->assertDontSeeHtml('value="client"');

    Role::query()->where('name', TeamRole::Admin->value)->delete();

    Livewire::test('pages::users.index')
        ->assertDontSeeHtml('value="admin"')
        ->assertSeeHtml('value="member"');
});

test('the users page lists employees and hides client users', function () {
    ['owner' => $owner, 'team' => $team, 'client' => $client] = clientPortalFixtures();

    $employee = attachTeamMember($team, User::factory()->create([
        'name' => 'Riley Employee',
        'email' => 'riley@example.com',
    ]));

    $clientUser = User::factory()->create([
        'name' => 'Casey Client',
        'email' => 'casey@example.com',
    ]);

    attachClientUser($team, $client, $clientUser);

    $this->actingAs($owner);

    Livewire::test('pages::users.index')
        ->assertSee('Riley Employee')
        ->assertSee($owner->name)
        ->assertDontSee('Casey Client');

    Livewire::test('pages::users.show', ['user' => $clientUser])
        ->assertNotFound();

    expect($employee->teamRole($team))->toBe(TeamRole::Member);
});

test('an employee cannot open user management', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $employee = attachTeamMember($owner->currentTeam, User::factory()->create([
        'email' => 'riley@example.com',
    ]));

    $this->actingAs($employee);

    Livewire::test('pages::users.index')
        ->assertForbidden();
});

test('an owner cannot delete their own account from user management', function () {
    $owner = User::factory()->create([
        'name' => 'Avery Owner',
        'email' => 'owner@example.com',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::users.show', ['user' => $owner])
        ->assertSee('The team owner is managed from profile settings.')
        ->call('delete')
        ->assertForbidden();

    $this->assertDatabaseHas('users', [
        'id' => $owner->id,
    ]);
});

test('a user role must be admin or member', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::users.index')
        ->set('name', 'Casey Client')
        ->set('email', 'casey@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('role', TeamRole::Client->value)
        ->call('create')
        ->assertHasErrors('role');

    $this->assertDatabaseMissing('users', [
        'email' => 'casey@example.com',
    ]);
});

test('an admin can create an employee', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $admin = attachTeamMember($owner->currentTeam, User::factory()->create([
        'email' => 'admin@example.com',
    ]), TeamRole::Admin);

    $this->actingAs($admin);

    Livewire::test('pages::users.index')
        ->set('name', 'Riley Employee')
        ->set('email', 'riley@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('role', TeamRole::Member->value)
        ->call('create')
        ->assertHasNoErrors();

    $employee = User::query()->where('email', 'riley@example.com')->first();

    expect($employee?->teamRole($owner->currentTeam))->toBe(TeamRole::Member);
});
