<?php

use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a client user can be created from prompts', function () {
    $owner = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $team = $owner->currentTeam;

    $this->artisan('client:create')
        ->expectsQuestion('Client name', 'Acme Corp')
        ->expectsQuestion('Name', 'Grace Hopper')
        ->expectsQuestion('Email', 'client@acme.test')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->expectsOutputToContain('Client user client@acme.test created.')
        ->expectsOutputToContain('Sign in at /acme-corp/dashboard')
        ->assertSuccessful();

    $clientUser = User::query()->where('email', 'client@acme.test')->first();
    $client = Client::query()->where('name', 'Acme Corp')->first();

    expect($clientUser)->not->toBeNull()
        ->and($client)->not->toBeNull()
        ->and($clientUser->name)->toBe('Grace Hopper')
        ->and($clientUser->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $clientUser->password))->toBeTrue()
        ->and($clientUser->currentTeam?->is($team))->toBeTrue()
        ->and($clientUser->teamRole($team))->toBe(TeamRole::Client)
        ->and($clientUser->clientForTeam($team)?->is($client))->toBeTrue()
        ->and($clientUser->ownedTeams()->count())->toBe(0)
        ->and($client->slug)->toBe('acme-corp')
        ->and($client->contact_email)->toBe('client@acme.test')
        ->and($client->team_id)->toBe($team->id)
        ->and(Team::query()->count())->toBe(1);
});

test('client creation stops when no team exists', function () {
    $this->artisan('client:create')
        ->expectsOutputToContain('Create an admin first with php artisan admin:create.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Client::query()->count())->toBe(0);
});

test('client creation stops when the email is already taken', function () {
    User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $this->artisan('client:create')
        ->expectsQuestion('Client name', 'Acme Corp')
        ->expectsQuestion('Name', 'Grace Hopper')
        ->expectsQuestion('Email', 'ada@example.com')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->where('email', 'client@acme.test')->exists())->toBeFalse()
        ->and(Client::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1);
});

test('a mismatched client password is requested again', function () {
    $owner = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $team = $owner->currentTeam;

    $this->artisan('client:create')
        ->expectsQuestion('Client name', 'Acme Corp')
        ->expectsQuestion('Name', 'Grace Hopper')
        ->expectsQuestion('Email', 'client@acme.test')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'different')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->assertSuccessful();

    $clientUser = User::query()->where('email', 'client@acme.test')->first();

    expect($clientUser)->not->toBeNull()
        ->and($clientUser->teamRole($team))->toBe(TeamRole::Client)
        ->and($clientUser->currentTeam?->is($team))->toBeTrue();
});
