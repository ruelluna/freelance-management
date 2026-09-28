<?php

use App\Actions\Users\CreateClientUser;
use App\Actions\Users\CreateStaffUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\ClientStatus;
use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use App\Queries\StaffRoleQuery;
use App\Services\TeamResourceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Users')] class extends Component {
    use Interactions;
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public string $role = 'member';

    public string $clientId = '';

    public function mount(): void
    {
        if (Auth::user()->isTeamClient($this->team())) {
            return;
        }

        Gate::authorize('viewAny', [User::class, $this->team()]);
    }

    public function create(CreateStaffUser $staffUsers, CreateClientUser $clientUsers): void
    {
        Gate::authorize('create', [User::class, $this->team()]);

        $validated = $this->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'role' => ['required', Rule::in($this->roles())],
            'clientId' => [
                Rule::requiredIf($this->role === TeamRole::Client->value),
                Rule::excludeIf($this->role !== TeamRole::Client->value),
                'string',
                Rule::exists('clients', 'id')->where(
                    fn ($query) => $query
                        ->where('team_id', $this->team()->id)
                        ->where('status', ClientStatus::Active->value),
                ),
            ],
        ]);

        $role = TeamRole::from($validated['role']);

        if ($role->isClient()) {
            $client = $this->team()->clients()->whereKey($validated['clientId'])->firstOrFail();

            $clientUsers->handle(
                $this->team(),
                $client,
                $validated['name'],
                $validated['email'],
                $validated['password'],
            );
        } else {
            $staffUsers->handle(
                $this->team(),
                $validated['name'],
                $validated['email'],
                $validated['password'],
                $role,
            );
        }

        $this->reset('name', 'email', 'password', 'password_confirmation', 'role', 'clientId');
        $this->role = TeamRole::Member->value;

        $this->toast()->success(__('User created.'))->send();

        unset($this->users);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        if (Auth::user()->isTeamClient($this->team())) {
            return TeamResourceAccess::for(Auth::user(), $this->team())->assignableUsers();
        }

        return $this->team()
            ->members()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return $this->team()
            ->clients()
            ->where('status', ClientStatus::Active)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    protected function roles(): array
    {
        return (new StaffRoleQuery)->names(except: [TeamRole::Owner]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function roleOptions(): array
    {
        return (new StaffRoleQuery)->options(except: [TeamRole::Owner]);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('create', [User::class, $this->team()]);
    }

    #[Computed]
    public function isClientUser(): bool
    {
        return Auth::user()->isTeamClient($this->team());
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Users') }}</h1>
        <p class="text-sm text-gray-500 dark:text-dark-300">{{ $this->isClientUser ? __('People you can assign on your projects.') : __('Add employees, update their access, and choose who can be assigned to tasks.') }}</p>
    </div>

    @if ($this->canManage)
        <x-card>
            <form wire:submit="create" class="space-y-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Add user') }}</h2>
                <x-input wire:model="name" :label="__('Name')" data-test="user-name" />
                <x-input wire:model="email" type="email" :label="__('Email')" data-test="user-email" />
                <x-password
                    wire:model="password"
                    :label="__('Password')"
                    autocomplete="new-password"
                    data-test="user-password"
                />
                <x-password
                    wire:model="password_confirmation"
                    :label="__('Confirm password')"
                    autocomplete="new-password"
                    data-test="user-password-confirmation"
                />
                <x-select.native wire:model.live="role" :label="__('Role')" data-test="user-role">
                    @foreach ($this->roleOptions as $roleOption)
                        <option value="{{ $roleOption['value'] }}">{{ $roleOption['label'] }}</option>
                    @endforeach
                </x-select.native>
                @if ($role === 'client')
                    <x-select.native wire:model="clientId" :label="__('Client')" data-test="user-client">
                        <option value="">{{ __('Select a client') }}</option>
                        @foreach ($this->clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </x-select.native>
                @endif
                <x-button submit data-test="create-user" :text="__('Add user')" />
            </form>
        </x-card>
    @endif

    <div class="space-y-2">
        @forelse ($this->users as $member)
            <x-card wire:key="user-{{ $member->id }}">
                <div class="flex items-center justify-between gap-4" data-test="user-row">
                    <div>
                        @if ($this->canManage)
                            <a href="{{ route('users.show', $member) }}" class="font-medium hover:underline" wire:navigate data-test="user-link">
                                {{ $member->name }}
                            </a>
                        @else
                            <span class="font-medium" data-test="user-link">{{ $member->name }}</span>
                        @endif
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ $member->email }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        @if (can_impersonate() && can_be_impersonated($member))
                            <x-button
                                outline
                                sm
                                :href="route('impersonate', $member)"
                                data-test="view-as-user"
                                :text="__('View as')"
                            />
                        @endif
                        <x-badge light color="gray" :text="$member->pivot->role->label()" />
                    </div>
                </div>
            </x-card>
        @empty
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No users yet.') }}</p>
        @endforelse
    </div>
</div>
