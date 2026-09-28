<?php

use App\Actions\Connections\ConnectGithub;
use App\Actions\Connections\DisconnectConnection;
use App\Data\Integrations\RemoteSource;
use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Team;
use App\Services\Integrations\GithubIssueProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public Project $project;

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

    #[Validate('required|string')]
    public string $selectedRepo = '';

    public bool $tokenVerified = false;

    public string $repoSearch = '';

    public function mount(Project $project): void
    {
        abort_unless($project->team_id === $this->team()->id, 404);

        Gate::authorize('viewAny', [Connection::class, $this->team()]);

        $this->project = $project;
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
            $this->selectedRepo = '';
            $this->tokenVerified = true;
        } catch (\Throwable) {
            $this->addError('token', __('GitHub rejected that token. Check the PAT and try again.'));
            $this->tokenVerified = false;
            $this->availableRepos = [];
            $this->repoPrivacy = [];
            $this->searchMatchedRepos = [];
            $this->selectedRepo = '';
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

        $connectGithub->handle($this->project, $this->token, $this->name, $this->selectedRepo);

        $this->reset('token', 'availableRepos', 'repoPrivacy', 'searchMatchedRepos', 'selectedRepo', 'tokenVerified', 'repoSearch');
        $this->name = 'GitHub';

        unset($this->githubConnection);

        $this->dispatch('close-modal', name: 'connect-github');
        $this->dispatch('project-integration-updated');

        $this->toast()->success(__('GitHub connected. Issues will sync in the background.'))->send();
    }

    public function disconnect(string $connectionId, DisconnectConnection $disconnect): void
    {
        $connection = $this->project->connections()->findOrFail($connectionId);

        Gate::authorize('delete', $connection);

        $disconnect->handle($connection);

        unset($this->githubConnection);

        $this->dispatch('close-modal', name: 'disconnect-'.$connectionId);
        $this->dispatch('project-integration-updated');

        $this->toast()->success(__('Connection removed.'))->send();
    }

    public function sync(string $connectionId): void
    {
        $connection = $this->project->connections()->with('sources')->findOrFail($connectionId);

        Gate::authorize('sync', $connection);

        foreach ($connection->sources as $source) {
            SyncConnectedSourceJob::dispatch($source->id);
        }

        $this->toast()->success(__('Sync queued.'))->send();
    }

    #[Computed]
    public function githubConnection(): ?Connection
    {
        if (! Provider::Github->isAvailable()) {
            return null;
        }

        return $this->project->connections()
            ->where('provider', Provider::Github)
            ->with('sources')
            ->first();
    }

    /**
     * @return Collection<int, Provider>
     */
    #[Computed]
    public function availableProviders(): Collection
    {
        return collect(Provider::cases())
            ->filter(fn (Provider $provider): bool => $provider->isAvailable())
            ->values();
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

<div class="flex flex-col gap-3">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Integrations') }}</h2>

    @foreach ($this->availableProviders as $provider)
        @if ($provider === \App\Enums\Provider::Github)
            <x-card wire:key="integration-github">
                @if ($this->githubConnection)
                    <div data-test="connection-row">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $this->githubConnection->name }}</h3>
                                    <x-badge color="gray" light :text="$provider->label()" />
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">
                                    {{ $this->githubConnection->sources->first()?->name ?? __('No repository') }}
                                    · {{ $this->githubConnection->last_synced_at?->diffForHumans() ?? __('Not synced yet') }}
                                </p>
                            </div>

                            <div class="flex items-center gap-1">
                                <x-button outline sm wire:click="sync('{{ $this->githubConnection->id }}')" data-test="sync-connection" :text="__('Sync')" />
                                <x-button outline sm x-on:click="$tsui.open.modal('disconnect-{{ $this->githubConnection->id }}')" data-test="disconnect-connection" :text="__('Disconnect')" />
                            </div>
                        </div>
                    </div>

                    <x-modal :id="'disconnect-'.$this->githubConnection->id" :title="__('Disconnect :name?', ['name' => $this->githubConnection->name])" center size="lg">
                        <form id="disconnect-form-{{ $this->githubConnection->id }}" wire:submit="disconnect('{{ $this->githubConnection->id }}')">
                            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Synced issues from this connection will be removed.') }}</p>
                        </form>
                        <x-slot:footer>
                            <div class="flex w-full justify-end gap-2">
                                <x-button outline x-on:click="$tsui.close.modal('disconnect-{{ $this->githubConnection->id }}')" :text="__('Cancel')" />
                                <x-button submit form="disconnect-form-{{ $this->githubConnection->id }}" color="red" data-test="disconnect-confirm" :text="__('Disconnect')" />
                            </div>
                        </x-slot:footer>
                    </x-modal>
                @else
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $provider->label() }}</h3>
                            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Import issues from one repository into this project.') }}</p>
                        </div>
                        <x-button icon="plus" x-on:click="$tsui.open.modal('connect-github')" :text="__('Connect GitHub')" />
                    </div>
                @endif
            </x-card>
        @endif
    @endforeach

    <livewire:projects.superhuman :project="$project" :key="'project-superhuman-'.$project->id" />

    @if (! $this->githubConnection)
        <x-modal id="connect-github" :title="__('Connect GitHub')" center size="lg">
            @if ($errors->isNotEmpty())
                <div x-init="$tsui.open.modal('connect-github')"></div>
            @endif

            <form id="connect-github-form" wire:submit="connect" class="space-y-6">
                <p class="text-sm text-gray-500 dark:text-dark-300">
                    {{ __('Use a fine-grained PAT with Issues read/write on the repository you want. For a private repo, grant Repository access to that repo (or All repositories) and Metadata read access.') }}
                </p>

                <x-input wire:model="name" :label="__('Name')" data-test="connection-name" />
                <x-input wire:model="token" type="password" :label="__('Personal access token')" data-test="github-token" />

                <x-button outline wire:click="fetchRepos" data-test="fetch-repos" :text="__('Load repositories')" />

                @if ($tokenVerified)
                    <x-input
                        wire:model.live.debounce.300ms="repoSearch"
                        icon="magnifying-glass"
                        :placeholder="__('Search repositories')"
                        data-test="repo-search"
                    />

                    <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-dark-700">
                        @forelse ($this->filteredRepos as $repo)
                            <label class="flex items-center gap-2" wire:key="repo-{{ $repo }}">
                                <input type="radio" name="selectedRepo" value="{{ $repo }}" wire:model="selectedRepo" data-test="repo-option">
                                <span class="flex items-center gap-2">
                                    <span>{{ $repo }}</span>
                                    @if ($repoPrivacy[$repo] ?? false)
                                        <x-badge sm color="gray" light :text="__('Private')" />
                                    @endif
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-dark-300">
                                @if ($availableRepos === [])
                                    {{ __('No repositories found for this token.') }}
                                @elseif (strlen(trim($repoSearch)) >= 2)
                                    {{ __('No repositories match your search. Check that your PAT includes the private repo under Repository access.') }}
                                @else
                                    {{ __('No repositories match your search.') }}
                                @endif
                            </p>
                        @endforelse
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <div class="flex w-full justify-end gap-2">
                    <x-button outline x-on:click="$tsui.close.modal('connect-github')" :text="__('Cancel')" />
                    <x-button submit form="connect-github-form" :disabled="! $tokenVerified" data-test="connect-github-submit" :text="__('Connect')" />
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
