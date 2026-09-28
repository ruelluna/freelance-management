<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('the role seeder creates owner, admin, member, and client', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('name')->all())->toBe([
        TeamRole::Admin->value,
        TeamRole::Client->value,
        TeamRole::Member->value,
        TeamRole::Owner->value,
    ]);

    $owner = Role::findByName(TeamRole::Owner->value);
    $admin = Role::findByName(TeamRole::Admin->value);
    $member = Role::findByName(TeamRole::Member->value);
    $client = Role::findByName(TeamRole::Client->value);

    $ownerPermissions = collect(TeamPermission::cases())->map->value->sort()->values()->all();

    expect($owner->permissions->pluck('name')->sort()->values()->all())->toBe($ownerPermissions);

    expect($admin->hasPermissionTo(TeamPermission::ManageUsers))->toBeTrue()
        ->and($admin->hasPermissionTo(TeamPermission::ManageProjects))->toBeTrue()
        ->and($admin->hasPermissionTo(TeamPermission::DeleteTeam))->toBeFalse();

    expect($member->hasPermissionTo(TeamPermission::ViewIssues))->toBeTrue()
        ->and($member->hasPermissionTo(TeamPermission::CreateIssues))->toBeTrue()
        ->and($member->hasPermissionTo(TeamPermission::CommentOnIssues))->toBeTrue()
        ->and($member->hasPermissionTo(TeamPermission::AssignIssues))->toBeTrue()
        ->and($member->hasPermissionTo(TeamPermission::ManageProjects))->toBeFalse()
        ->and($member->hasPermissionTo(TeamPermission::ManageUsers))->toBeFalse();

    expect($client->hasPermissionTo(TeamPermission::ViewIssues))->toBeTrue()
        ->and($client->hasPermissionTo(TeamPermission::CreateIssues))->toBeTrue()
        ->and($client->hasPermissionTo(TeamPermission::UpdateIssues))->toBeTrue()
        ->and($client->hasPermissionTo(TeamPermission::CommentOnIssues))->toBeTrue()
        ->and($client->hasPermissionTo(TeamPermission::AssignIssues))->toBeTrue();

    expect(Permission::query()->count())->toBe(count(TeamPermission::cases()));

    $this->seed(RoleSeeder::class);

    expect(Role::query()->count())->toBe(4)
        ->and(Permission::query()->count())->toBe(count(TeamPermission::cases()));
});
