<?php

use App\Actions\Clients\CreateClient;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Clients')] class extends Component {
    use Interactions;

    public string $name = '';

    public string $contactEmail = '';

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', [Client::class, $this->team()]);
    }

    public function create(CreateClient $action): void
    {
        Gate::authorize('create', [Client::class, $this->team()]);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ]);

        $action->handle(
            $this->team(),
            $validated['name'],
            $validated['contactEmail'] ?? null,
            $validated['notes'] ?? null,
        );

        $this->reset('name', 'contactEmail', 'notes');

        $this->toast()->success(__('Client created.'))->send();

        unset($this->clients);
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return $this->team()
            ->clients()
            ->withCount(['projects', 'users'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('create', [Client::class, $this->team()]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Clients') }}</h1>
        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Create clients, invite their users, and assign projects they can access.') }}</p>
    </div>

    @if ($this->canManage)
        <x-card>
            <form wire:submit="create" class="space-y-4">
                <x-input wire:model="name" :label="__('Name')" data-test="client-name" />
                <x-input wire:model="contactEmail" type="email" :label="__('Contact email')" data-test="client-contact-email" />
                <x-editor markdown wire:model="notes" :label="__('Internal notes')" min-height="6rem" data-test="client-notes" />
                <x-button submit data-test="create-client" :text="__('Add client')" />
            </form>
        </x-card>
    @endif

    <div class="space-y-2">
        @forelse ($this->clients as $client)
            <x-card wire:key="client-{{ $client->id }}">
                <div class="flex items-center justify-between gap-4" data-test="client-row">
                    <div>
                        <a href="{{ route('clients.show', $client) }}" class="font-medium hover:underline" wire:navigate data-test="client-link">
                            {{ $client->name }}
                        </a>
                        <p class="text-sm text-gray-500 dark:text-dark-300">
                            {{ $client->contact_email ?? __('No contact email') }}
                            · {{ trans_choice(':count user|:count users', $client->users_count) }}
                            · {{ trans_choice(':count project|:count projects', $client->projects_count) }}
                        </p>
                    </div>

                    <x-badge light :color="$client->status === ClientStatus::Active ? 'green' : 'gray'" :text="$client->status->label()" />
                </div>
            </x-card>
        @empty
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No clients yet.') }}</p>
        @endforelse
    </div>
</div>
