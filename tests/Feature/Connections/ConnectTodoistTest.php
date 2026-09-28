<?php

use App\Enums\Provider;
use App\Enums\TeamRole;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('an owner can connect a personal todoist account', function () {
    Queue::fake();
    Http::preventStrayRequests();

    Http::fake([
        'https://api.todoist.com/api/v1/tasks*' => Http::response([
            'results' => [],
            'next_cursor' => null,
        ]),
    ]);

    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::settings.todoist')
        ->set('name', 'My Todoist')
        ->set('token', 'todoist-token-value-123456')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = Connection::query()->first();

    expect($connection)
        ->name->toBe('My Todoist')
        ->provider->toBe(Provider::Todoist)
        ->team_id->toBe($owner->currentTeam->id)
        ->user_id->toBe($owner->id)
        ->project_id->toBeNull()
        ->token->toBe('todoist-token-value-123456');

    expect($connection->sources()->first())
        ->name->toBe('All tasks')
        ->external_id->toBe('all');

    Queue::assertPushed(SyncConnectedSourceJob::class);
});

test('connecting again updates the token instead of creating another connection', function () {
    Queue::fake();
    Http::preventStrayRequests();

    Http::fake([
        'https://api.todoist.com/api/v1/tasks*' => Http::response([
            'results' => [],
            'next_cursor' => null,
        ]),
    ]);

    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::settings.todoist')
        ->set('token', 'todoist-token-value-123456')
        ->call('connect')
        ->assertHasNoErrors();

    Livewire::test('pages::settings.todoist')
        ->set('token', 'todoist-token-value-654321')
        ->call('connect')
        ->assertHasNoErrors();

    expect(Connection::query()->count())->toBe(1);
    expect(Connection::query()->first()->token)->toBe('todoist-token-value-654321');
});

test('a rejected todoist token is not saved', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://api.todoist.com/api/v1/tasks*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    Livewire::test('pages::settings.todoist')
        ->set('token', 'todoist-token-value-123456')
        ->call('connect')
        ->assertHasErrors('token');

    expect(Connection::query()->count())->toBe(0);
});

test('a member cannot connect todoist', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $member = attachTeamMember($owner->currentTeam, User::factory()->create([
        'email' => 'member@example.com',
    ]), TeamRole::Member);

    $this->actingAs($member);

    Livewire::test('pages::settings.todoist')
        ->assertForbidden();
});

test('the team owner sees todoist settings', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('todoist.edit'), false);

    $this->get(route('todoist.edit'))->assertOk();
});

test('only the team owner can see todoist settings', function (TeamRole $role) {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $user = attachTeamMember($owner->currentTeam, User::factory()->create([
        'email' => $role->value.'@example.com',
    ]), $role);

    $this->actingAs($user);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee(route('todoist.edit'), false);

    $this->get(route('todoist.edit'))->assertForbidden();
})->with([
    TeamRole::Admin,
    TeamRole::Member,
    TeamRole::Client,
]);
