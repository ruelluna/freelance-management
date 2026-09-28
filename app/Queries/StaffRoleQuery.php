<?php

namespace App\Queries;

use App\Enums\TeamRole;
use Spatie\Permission\Models\Role;

class StaffRoleQuery
{
    /**
     * Roles from the database that can be chosen on a user form. Owner stays off every form.
     *
     * @param  array<int, TeamRole>  $except
     * @return array<int, array{value: string, label: string}>
     */
    public function options(array $except = [TeamRole::Owner, TeamRole::Client]): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $name): ?TeamRole => TeamRole::tryFrom($name))
            ->filter(fn (?TeamRole $role): bool => $role !== null && ! in_array($role, $except, true))
            ->map(fn (TeamRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, TeamRole>  $except
     * @return array<int, string>
     */
    public function names(array $except = [TeamRole::Owner, TeamRole::Client]): array
    {
        return array_column($this->options($except), 'value');
    }
}
