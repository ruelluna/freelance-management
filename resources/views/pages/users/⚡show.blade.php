<?php

use App\Actions\Users\DeleteStaffUser;
use App\Actions\Users\UpdateStaffUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Queries\StaffRoleQuery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('User')] class extends Component {
    use Interactions;
    use PasswordValidationRules;
    use ProfileValidationRules;

    public User $member;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public string $role = '';

    public function mount(User $user): void
    {
        $role = $user->teamRole($this->team());

        abort_unless($role !== null, 404);

        Gate::authorize('view', $user);

        $this->member = $user;
        $this->fillFromMember();
    }

    public function save(UpdateStaffUser $action): void
    {
        Gate::authorize('update', $this->member);

        $rules = [
            ...$this->profileRules($this->member->id),
            'role' => ['required', Rule::in($this->roles())],
        ];

        if (filled($this->password)) {
            $rules['password'] = $this->passwordRules();
        }

        $validated = $this->validate($rules);

        $this->member = $action->handle(
            $this->team(),
            $this->member,
            $validated['name'],
            $validated['email'],
            TeamRole::from($validated['role']),
            filled($validated['password'] ?? null) ? $validated['password'] : null,
        );

        $this->fillFromMember();

        $this->toast()->success(__('User saved.'))->send();

        $this->redirectRoute('users.show', ['user' => $this->member->id], navigate: true);
    }

    public function delete(DeleteStaffUser $action): void
    {
        Gate::authorize('delete', $this->member);

        $action->handle($this->team(), $this->member);

        $this->redirect(route('users.index'), navigate: true);
    }

    /**
     * @return array<int, string>
     */
    protected function roles(): array
    {
        return (new StaffRoleQuery)->names(except: $this->roleExceptions());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function roleOptions(): array
    {
        return (new StaffRoleQuery)->options(except: $this->roleExceptions());
    }

    /**
     * @return array<int, TeamRole>
     */
    protected function roleExceptions(): array
    {
        if ($this->member->teamRole($this->team())?->isClient()) {
            return [TeamRole::Owner, TeamRole::Admin, TeamRole::Member];
        }

        return [TeamRole::Owner, TeamRole::Client];
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Auth::user()->can('update', $this->member);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->can('delete', $this->member);
    }

    #[Computed]
    public function roleLabel(): string
    {
        return $this->member->teamRole($this->team())?->label() ?? '';
    }

    protected function fillFromMember(): void
    {
        $this->name = $this->member->name;
        $this->email = $this->member->email;
        $this->role = $this->member->teamRole($this->team())?->value ?? TeamRole::Member->value;
        $this->reset('password', 'password_confirmation');
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('users.index') }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to users') }}</a>
    </div>

    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $member->name }}</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">{{ $member->email }} · {{ $this->roleLabel }}</p>
    </div>

    @if ($this->canUpdate)
        <x-card>
            <form wire:submit="save" class="space-y-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit user') }}</h2>
                <x-input wire:model="name" :label="__('Name')" data-test="edit-user-name" />
                <x-input wire:model="email" type="email" :label="__('Email')" data-test="edit-user-email" />
                <x-select.native wire:model="role" :label="__('Role')" data-test="edit-user-role">
                    @foreach ($this->roleOptions as $roleOption)
                        <option value="{{ $roleOption['value'] }}">{{ $roleOption['label'] }}</option>
                    @endforeach
                </x-select.native>
                <x-password
                    wire:model="password"
                    :label="__('Password')"
                    :rules="[]"
                    autocomplete="new-password"
                    data-test="edit-user-password"
                />
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Leave blank to keep the current password.') }}</p>
                <x-password
                    wire:model="password_confirmation"
                    :label="__('Confirm password')"
                    :rules="[]"
                    autocomplete="new-password"
                    data-test="edit-user-password-confirmation"
                />
                <div class="flex flex-wrap gap-2">
                    <x-button submit data-test="save-user" :text="__('Save user')" />
                    @if ($this->canDelete)
                        <x-button outline x-on:click="$tsui.open.modal('delete-user')" data-test="delete-user" :text="__('Delete')" />
                    @endif
                </div>
            </form>
        </x-card>

        @if ($this->canDelete)
            <x-modal id="delete-user" :title="__('Delete :name?', ['name' => $member->name])" center size="lg">
                <form id="delete-user-form" wire:submit="delete">
                    <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('This removes their access to the team and unassigns their tasks.') }}</p>
                </form>
                <x-slot:footer>
                    <div class="flex w-full justify-end gap-2">
                        <x-button outline x-on:click="$tsui.close.modal('delete-user')" :text="__('Cancel')" />
                        <x-button submit form="delete-user-form" color="red" data-test="delete-user-confirm" :text="__('Delete')" />
                    </div>
                </x-slot:footer>
            </x-modal>
        @endif
    @else
        <x-card>
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('The team owner is managed from profile settings.') }}</p>
        </x-card>
    @endif
</div>
