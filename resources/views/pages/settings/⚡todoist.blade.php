<?php

use App\Actions\Connections\ConnectTodoist;
use App\Actions\Connections\DisconnectConnection;
use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Title('Todoist')] class extends Component {
    use Interactions;

    #[Validate('required|string|max:255')]
    public string $name = 'Todoist';

    #[Validate('required|string|min:20')]
    public string $token = '';

    public function mount(): void
    {
        $this->authorizeTodoist();

        if ($this->connection !== null) {
            $this->name = $this->connection->name;
        }
    }

    public function connect(ConnectTodoist $connectTodoist): void
    {
        $team = $this->authorizeTodoist();

        $this->validate();

        try {
            $connectTodoist->handle($team, Auth::user(), $this->token, $this->name);
        } catch (\Throwable) {
            $this->addError('token', __('Todoist rejected that token. Check the API token and try again.'));

            return;
        }

        $this->reset('token');
        unset($this->connection);

        $this->toast()->success(__('Todoist connected. Tasks will sync in the background.'))->send();
    }

    public function sync(): void
    {
        $this->authorizeTodoist();

        $connection = $this->connection;

        abort_if($connection === null, 404);

        foreach ($connection->sources as $source) {
            SyncConnectedSourceJob::dispatch($source->id);
        }

        $this->toast()->success(__('Sync queued.'))->send();
    }

    public function disconnect(DisconnectConnection $disconnect): void
    {
        $this->authorizeTodoist();

        $connection = $this->connection;

        abort_if($connection === null, 404);

        $disconnect->handle($connection);

        $this->reset('token');
        $this->name = 'Todoist';
        unset($this->connection);

        $this->toast()->success(__('Todoist disconnected.'))->send();
    }

    #[Computed]
    public function connection(): ?Connection
    {
        $team = $this->team();

        if ($team === null) {
            return null;
        }

        return $team->connections()
            ->where('provider', Provider::Todoist)
            ->where('user_id', Auth::id())
            ->with('sources')
            ->first();
    }

    protected function team(): ?Team
    {
        return Auth::user()->currentTeam;
    }

    protected function authorizeTodoist(): Team
    {
        $team = $this->team();

        abort_unless($team !== null && Auth::user()->canManageTodoist($team), 403);

        return $team;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Todoist settings') }}</h2>

    <x-pages::settings.layout :heading="__('Todoist')" :subheading="__('Import open Todoist tasks created from July 2026 onward. After the first sync, only new tasks are added. They stay private until you assign a project or a person, and new tasks are fetched every 10 minutes.')">
        @if ($this->connection)
            <div class="mb-6 space-y-2" data-test="todoist-connection">
                <p class="text-sm text-gray-900 dark:text-white">{{ $this->connection->name }}</p>
                <p class="text-sm text-gray-500 dark:text-dark-300">
                    {{ $this->connection->last_synced_at?->diffForHumans() ?? __('Not synced yet') }}
                </p>
                <div class="flex items-center gap-2">
                    <x-button outline sm wire:click="sync" data-test="sync-todoist" :text="__('Sync')" />
                    <x-button outline sm color="red" wire:click="disconnect" wire:confirm="{{ __('Synced tasks from Todoist will be removed.') }}" data-test="disconnect-todoist" :text="__('Disconnect')" />
                </div>
            </div>
        @endif

        <form wire:submit="connect" class="my-6 w-full space-y-6">
            <x-input wire:model="name" :label="__('Name')" data-test="todoist-name" />
            <x-input wire:model="token" type="password" :label="__('API token')" data-test="todoist-token" />
            <p class="text-sm text-gray-500 dark:text-dark-300">
                {{ __('Create a token in Todoist under Settings, then Integrations, then Developer.') }}
            </p>
            <x-button submit :text="$this->connection ? __('Update token') : __('Connect')" data-test="connect-todoist" />
        </form>
    </x-pages::settings.layout>
</section>
