<?php

use App\Actions\Connections\ConnectGithub;
use App\Actions\Connections\DisconnectConnection;
use App\Data\Integrations\RemoteSource;
use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Team;
use App\Services\Integrations\GithubIssueProvider;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Connections')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = 'GitHub';

    #[Validate('required|string|min:20')]
    public string $token = '';

    /**
     * @var array<int, string>
     */
    public array $availableRepos = [];

    /**
     * @var array<string, bool>
     */
    public array $repoPrivacy = [];

    /**
     * @var array<int, string>
     */
    public array $searchMatchedRepos = [];

    /**
     * @var array<int, string>
     */
    #[Validate('required|array|min:1')]
    public array $selectedRepos = [];

    public bool $tokenVerified = false;

    public string $repoSearch = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', [Connection::class, $this->team()]);
    }

    public function fetchRepos(GithubIssueProvider $github): void
    {
        Gate::authorize('create', [Connection::class, $this->team()]);

        $this->validateOnly('token');

        try {
            $preview = new Connection([
                'provider' => Provider::Github,
                'token' => $this->token,
            ]);

            $sources = $github->listSources($preview);

            $this->availableRepos = $sources->pluck('externalId')->all();
            $this->repoPrivacy = $sources
                ->mapWithKeys(fn (RemoteSource $source): array => [
                    $source->externalId => (bool) ($source->meta['private'] ?? false),
                ])
                ->all();

            $this->repoSearch = '';
            $this->searchMatchedRepos = [];
            $this->tokenVerified = true;
        } catch (\Throwable) {
            $this->addError('token', __('GitHub rejected that token. Check the PAT and try again.'));
            $this->tokenVerified = false;
            $this->availableRepos = [];
            $this->repoPrivacy = [];
            $this->searchMatchedRepos = [];
            $this->repoSearch = '';
        }
    }

    public function updatedRepoSearch(GithubIssueProvider $github): void
    {
        if (! $this->tokenVerified) {
            return;
        }

        $search = trim($this->repoSearch);

        if (strlen($search) < 2) {
            $this->searchMatchedRepos = [];

            return;
        }

        try {
            $preview = new Connection([
                'provider' => Provider::Github,
                'token' => $this->token,
            ]);

            $sources = $github->searchSources($preview, $search);

            $this->searchMatchedRepos = $sources->pluck('externalId')->all();

            foreach ($sources as $source) {
                $this->repoPrivacy[$source->externalId] = (bool) ($source->meta['private'] ?? false);
            }
        } catch (\Throwable) {
            $this->searchMatchedRepos = [];
        }
    }

    public function connect(ConnectGithub $connectGithub): void
    {
        Gate::authorize('create', [Connection::class, $this->team()]);

        $this->validate();

        $connectGithub->handle($this->team(), $this->token, $this->name, $this->selectedRepos);

        $this->reset('token', 'availableRepos', 'repoPrivacy', 'searchMatchedRepos', 'selectedRepos', 'tokenVerified', 'repoSearch');
        $this->name = 'GitHub';

        $this->dispatch('close-modal', name: 'connect-github');

        Flux::toast(variant: 'success', text: __('GitHub connected. Issues will sync in the background.'));
    }

    public function disconnect(string $connectionId, DisconnectConnection $disconnect): void
    {
        $connection = $this->team()->connections()->findOrFail($connectionId);

        Gate::authorize('delete', $connection);

        $disconnect->handle($connection);

        $this->dispatch('close-modal', name: 'disconnect-'.$connectionId);

        Flux::toast(variant: 'success', text: __('Connection removed.'));
    }

    public function sync(string $connectionId): void
    {
        $connection = $this->team()->connections()->with('sources')->findOrFail($connectionId);

        Gate::authorize('sync', $connection);

        foreach ($connection->sources as $source) {
            SyncConnectedSourceJob::dispatch($source->id);
        }

        Flux::toast(variant: 'success', text: __('Sync queued.'));
    }

    /**
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->team()->connections()->with('sources')->latest()->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('create', [Connection::class, $this->team()]);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function filteredRepos(): array
    {
        $search = trim(strtolower($this->repoSearch));

        if ($search === '') {
            return $this->availableRepos;
        }

        $localMatches = array_values(array_filter(
            $this->availableRepos,
            fn (string $repo): bool => str_contains(strtolower($repo), $search),
        ));

        $remoteMatches = array_values(array_filter(
            $this->searchMatchedRepos,
            fn (string $repo): bool => str_contains(strtolower($repo), $search),
        ));

        return array_values(array_unique([...$localMatches, ...$remoteMatches]));
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Connections') }}</flux:heading>
                <flux:subheading>{{ __('Link GitHub repositories to consolidate issues here.') }}</flux:subheading>
            </div>

            @if ($this->canManage)
                <flux:modal.trigger name="connect-github">
                    <flux:button variant="primary" icon="plus">{{ __('Connect GitHub') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        <div class="space-y-3">
            @forelse ($this->connections as $connection)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="connection-{{ $connection->id }}" data-test="connection-row">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg">{{ $connection->name }}</flux:heading>
                                <flux:badge color="zinc">{{ $connection->provider->label() }}</flux:badge>
                            </div>
                            <flux:text class="mt-1 text-sm text-zinc-500">
                                {{ $connection->last_synced_at?->diffForHumans() ?? __('Not synced yet') }}
                            </flux:text>
                        </div>

                        @if ($this->canManage)
                            <div class="flex items-center gap-1">
                                <flux:button variant="ghost" size="sm" wire:click="sync('{{ $connection->id }}')" data-test="sync-connection">
                                    {{ __('Sync') }}
                                </flux:button>
                                <flux:modal.trigger :name="'disconnect-'.$connection->id">
                                    <flux:button variant="ghost" size="sm" data-test="disconnect-connection">{{ __('Disconnect') }}</flux:button>
                                </flux:modal.trigger>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @forelse ($connection->sources as $source)
                            <flux:badge color="zinc" wire:key="source-{{ $source->id }}">{{ $source->name }}</flux:badge>
                        @empty
                            <flux:text class="text-sm">{{ __('No repositories selected.') }}</flux:text>
                        @endforelse
                    </div>
                </div>

                @if ($this->canManage)
                    <flux:modal :name="'disconnect-'.$connection->id" class="max-w-lg">
                        <form wire:submit="disconnect('{{ $connection->id }}')" class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Disconnect :name?', ['name' => $connection->name]) }}</flux:heading>
                                <flux:subheading>{{ __('Synced issues from this connection will be removed.') }}</flux:subheading>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" type="submit" data-test="disconnect-confirm">{{ __('Disconnect') }}</flux:button>
                            </div>
                        </form>
                    </flux:modal>
                @endif
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                    <flux:text>{{ __('No connections yet. Connect a GitHub account to import issues.') }}</flux:text>
                </div>
            @endforelse
        </div>

    @if ($this->canManage)
        <flux:modal name="connect-github" :show="$errors->isNotEmpty()" class="max-w-lg">
            <form wire:submit="connect" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Connect GitHub') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Use a fine-grained PAT with Issues read/write on the repositories you want. For private repos, grant Repository access to those repos (or All repositories) and Metadata read access.') }}
                    </flux:subheading>
                </div>

                <flux:input wire:model="name" :label="__('Name')" data-test="connection-name" />
                <flux:input wire:model="token" type="password" :label="__('Personal access token')" data-test="github-token" />

                <flux:button type="button" variant="filled" wire:click="fetchRepos" data-test="fetch-repos">
                    {{ __('Load repositories') }}
                </flux:button>

                @if ($tokenVerified)
                    <flux:input
                        wire:model.live.debounce.300ms="repoSearch"
                        icon="magnifying-glass"
                        :placeholder="__('Search repositories')"
                        data-test="repo-search"
                    />

                    <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        @forelse ($this->filteredRepos as $repo)
                            <label class="flex items-center gap-2" wire:key="repo-{{ $repo }}">
                                <input type="checkbox" value="{{ $repo }}" wire:model="selectedRepos" data-test="repo-checkbox">
                                <span class="flex items-center gap-2">
                                    <span>{{ $repo }}</span>
                                    @if ($repoPrivacy[$repo] ?? false)
                                        <flux:badge size="sm" color="zinc">{{ __('Private') }}</flux:badge>
                                    @endif
                                </span>
                            </label>
                        @empty
                            <flux:text>
                                @if ($availableRepos === [])
                                    {{ __('No repositories found for this token.') }}
                                @elseif (strlen(trim($repoSearch)) >= 2)
                                    {{ __('No repositories match your search. Check that your PAT includes the private repo under Repository access.') }}
                                @else
                                    {{ __('No repositories match your search.') }}
                                @endif
                            </flux:text>
                        @endforelse
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" :disabled="! $tokenVerified" data-test="connect-github-submit">
                        {{ __('Connect') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
