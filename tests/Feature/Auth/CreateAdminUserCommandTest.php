<?php

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the first admin can be created from prompts', function () {
    $this->artisan('admin:create')
        ->expectsQuestion('Name', 'Ada Lovelace')
        ->expectsQuestion('Email', 'ada@example.com')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->expectsQuestion('Team name', 'Acme Studio')
        ->expectsOutputToContain('Admin ada@example.com created.')
        ->expectsOutputToContain('Sign in at /dashboard')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ada@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('Ada Lovelace')
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and($admin->currentTeam)->not->toBeNull()
        ->and($admin->currentTeam->name)->toBe('Acme Studio')
        ->and($admin->currentTeam->slug)->toBe('acme-studio')
        ->and($admin->currentTeam->is_personal)->toBeTrue()
        ->and($admin->teamRole($admin->currentTeam))->toBe(TeamRole::Owner);
});

test('admin creation stops when a user already exists', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $this->artisan('admin:create')
        ->expectsOutputToContain('An admin already exists.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

test('admin creation stops when the password is invalid', function () {
    $this->artisan('admin:create')
        ->expectsQuestion('Name', 'Ada Lovelace')
        ->expectsQuestion('Email', 'ada@example.com')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->expectsQuestion('Team name', "Ada Lovelace's Team")
        ->assertFailed();

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeFalse();
});

test('a mismatched password is requested again', function () {
    $this->artisan('admin:create')
        ->expectsQuestion('Name', 'Ada Lovelace')
        ->expectsQuestion('Email', 'ada@example.com')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'different')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->expectsQuestion('Team name', "Ada Lovelace's Team")
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ada@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->currentTeam->name)->toBe("Ada Lovelace's Team");
});
