<?php

namespace App\Queries;

use App\Enums\TeamRole;
use Spatie\Permission\Models\Role;

class StaffRoleQuery
{
    /**
     * Staff roles that can be assigned on the user form. Owner and client stay on their own flows.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $name): ?TeamRole => TeamRole::tryFrom($name))
            ->filter(fn (?TeamRole $role): bool => $role !== null && ! in_array($role, [TeamRole::Owner, TeamRole::Client], true))
            ->map(fn (TeamRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_column($this->options(), 'value');
    }
}
