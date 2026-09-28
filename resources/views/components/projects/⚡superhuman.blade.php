<?php

use App\Actions\Connections\ConnectSuperhuman;
use App\Actions\Connections\DisconnectConnection;
use App\Actions\Connections\SaveSuperhumanSource;
use App\Data\Integrations\RemoteSource;
use App\Enums\Provider;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Team;
use App\Services\Integrations\SuperhumanIssueProvider;
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
    public string $name = 'Superhuman Docs';

    #[Validate('required|string|min:20')]
    public string $token = '';

    #[Validate('required|string|max:255')]
    public string $docId = '';

    #[Validate('required|string|max:255')]
    public string $pageId = '';

    #[Validate('required|string|max:255')]
    public string $boardId = '';

    public string $accountEmail = '';

    public string $catalogError = '';

    public bool $tokenVerified = false;

    /**
     * @var array<int, array{id: string, name: string}>
     */
    public array $docs = [];

    /**
     * @var array<int, array{id: string, name: string}>
     */
    public array $pages = [];

    /**
     * @var array<int, array{id: string, name: string, doc_id?: string}>
     */
    public array $boards = [];

    public function mount(Project $project, SuperhumanIssueProvider $superhuman): void
    {
        abort_unless($project->team_id === $this->team()->id, 404);

        Gate::authorize('viewAny', [Connection::class, $this->team()]);

        $this->project = $project;

        if ($this->connection !== null) {
            $this->name = $this->connection->name;
            $this->accountEmail = strtolower((string) ($this->connection->settings['owner_email'] ?? ''));
            $this->tokenVerified = true;
            $this->hydrateSource();

            try {
                $this->refreshDocs($superhuman);
                $this->loadPages($superhuman);
                $this->loadBoards($superhuman);
            } catch (\Throwable) {
                $this->catalogError = __('Superhuman Docs could not list docs for this token.');
            }
        }
    }

    public function loadDocs(SuperhumanIssueProvider $superhuman, ConnectSuperhuman $connectSuperhuman): void
    {
        Gate::authorize('create', [Connection::class, $this->team()]);

        if ($this->token !== '') {
            $this->validateOnly('token');
        }

        try {
            if ($this->token !== '' && $this->connection !== null) {
                $connection = $connectSuperhuman->handle($this->project, Auth::user(), $this->token, $this->name);
                $this->accountEmail = strtolower((string) ($connection->settings['owner_email'] ?? ''));
                $this->reset('token');
                unset($this->connection);
            } elseif ($this->token !== '') {
                $this->accountEmail = $superhuman->ownerEmail($this->token);
            }

            $this->refreshDocs($superhuman);
            $this->tokenVerified = true;
        } catch (\Throwable) {
            $this->addError('token', __('Superhuman Docs rejected that token. Check the API token and try again.'));
            $this->tokenVerified = $this->connection !== null;
            $this->docs = [];
        }
    }

    public function docChanged(SuperhumanIssueProvider $superhuman): void
    {
        $this->pageId = '';
        $this->boardId = '';
        $this->pages = [];
        $this->boards = [];
        $this->loadPages($superhuman);
    }

    public function pageChanged(SuperhumanIssueProvider $superhuman): void
    {
        $this->boardId = '';
        $this->boards = [];
        $this->loadBoards($superhuman);
    }

    public function connect(ConnectSuperhuman $connectSuperhuman, SaveSuperhumanSource $saveSource, SuperhumanIssueProvider $superhuman): void
    {
        Gate::authorize('create', [Connection::class, $this->team()]);

        $this->validateOnly('name');
        $this->validateOnly('token');
        $this->validateOnly('docId');
        $this->validateOnly('pageId');
        $this->validateOnly('boardId');

        try {
            $connection = $connectSuperhuman->handle($this->project, Auth::user(), $this->token, $this->name);
            $this->accountEmail = strtolower((string) ($connection->settings['owner_email'] ?? ''));
            $saveSource->handle(
                $connection,
                $this->boardDocId(),
                $this->pageId,
                $this->optionName($this->pages, $this->pageId),
                $this->boardId,
                $this->optionName($this->boards, $this->boardId),
                $this->docId,
            );
        } catch (\Throwable) {
            $this->addError('token', __('Superhuman Docs could not save that table. Check the token and try again.'));

            return;
        }

        $this->reset('token');
        unset($this->connection);
        $this->tokenVerified = true;
        $this->refreshDocs($superhuman);

        $this->dispatch('close-modal', name: 'connect-superhuman-'.$this->project->id);
        $this->dispatch('project-integration-updated');

        $this->toast()->success(__('Superhuman Docs connected. Tasks will sync in the background.'))->send();
    }

    public function saveSource(SaveSuperhumanSource $saveSource): void
    {
        $connection = $this->connection;

        abort_if($connection === null, 404);

        Gate::authorize('sync', $connection);

        $this->validateOnly('docId');
        $this->validateOnly('pageId');
        $this->validateOnly('boardId');

        try {
            $saveSource->handle(
                $connection,
                $this->boardDocId(),
                $this->pageId,
                $this->optionName($this->pages, $this->pageId),
                $this->boardId,
                $this->optionName($this->boards, $this->boardId),
                $this->docId,
            );
        } catch (\Throwable) {
            $this->addError('boardId', __('Superhuman Docs could not save that table. Check the token and try again.'));

            return;
        }

        unset($this->connection);

        $this->toast()->success(__('Table saved. Tasks assigned to you will sync in the background.'))->send();
    }

    public function sync(): void
    {
        $connection = $this->connection;

        abort_if($connection === null, 404);

        Gate::authorize('sync', $connection);

        foreach ($connection->sources as $source) {
            SyncConnectedSourceJob::dispatch($source->id);
        }

        $this->toast()->success(__('Sync queued.'))->send();
    }

    public function disconnect(DisconnectConnection $disconnect): void
    {
        $connection = $this->connection;

        abort_if($connection === null, 404);

        Gate::authorize('delete', $connection);

        $disconnect->handle($connection);

        $this->reset('token', 'docId', 'pageId', 'boardId', 'accountEmail', 'catalogError', 'tokenVerified');
        $this->name = 'Superhuman Docs';
        $this->docs = [];
        $this->pages = [];
        $this->boards = [];
        unset($this->connection);

        $this->dispatch('close-modal', name: 'disconnect-superhuman-'.$this->project->id);
        $this->dispatch('project-integration-updated');

        $this->toast()->success(__('Superhuman Docs disconnected.'))->send();
    }

    #[Computed]
    public function connection(): ?Connection
    {
        return $this->project->connections()
            ->where('provider', Provider::Superhuman)
            ->with('sources')
            ->first();
    }

    #[Computed]
    public function replacingBoard(): bool
    {
        $source = $this->connection?->sources->first();

        if ($source === null || $this->docId === '' || $this->boardId === '') {
            return false;
        }

        return $source->external_id !== $this->boardDocId().'/'.$this->boardId;
    }

    protected function hydrateSource(): void
    {
        $source = $this->connection?->sources->first();

        if ($source === null) {
            return;
        }

        $parts = explode('/', $source->external_id, 2);
        $selectedDocId = $source->settings['selected_doc_id'] ?? null;
        $this->docId = is_string($selectedDocId) && $selectedDocId !== '' ? $selectedDocId : $parts[0];
        $this->boardId = $parts[1] ?? '';
        $pageId = $source->settings['page_id'] ?? '';
        $this->pageId = is_string($pageId) ? $pageId : '';
    }

    protected function refreshDocs(SuperhumanIssueProvider $superhuman): void
    {
        $this->docs = $this->options($superhuman->listDocs($this->apiConnection()));
        $this->catalogError = '';
    }

    protected function loadPages(SuperhumanIssueProvider $superhuman): void
    {
        if ($this->docId === '') {
            return;
        }

        try {
            $this->pages = $this->options($superhuman->listPages($this->apiConnection(), $this->docId));
            $this->catalogError = '';
        } catch (\Throwable) {
            $this->pages = [];
            $this->catalogError = __('Superhuman Docs could not list pages in that doc.');
        }
    }

    protected function loadBoards(SuperhumanIssueProvider $superhuman): void
    {
        if ($this->docId === '' || $this->pageId === '') {
            return;
        }

        try {
            $this->boards = $superhuman->listBoards($this->apiConnection(), $this->docId, $this->pageId)->values()->all();
            $this->catalogError = $this->boards === []
                ? __('This page has no table. If it is an embed, the source table could not be found.')
                : '';
        } catch (\Throwable) {
            $this->boards = [];
            $this->catalogError = __('Superhuman Docs could not list tables on that page.');
        }
    }

    protected function boardDocId(): string
    {
        foreach ($this->boards as $board) {
            $docId = $board['doc_id'] ?? null;

            if ($board['id'] === $this->boardId && is_string($docId) && $docId !== '') {
                return $docId;
            }
        }

        return $this->docId;
    }

    protected function apiConnection(): Connection
    {
        if ($this->token !== '') {
            return new Connection([
                'provider' => Provider::Superhuman,
                'token' => $this->token,
            ]);
        }

        $connection = $this->connection;

        if ($connection === null) {
            return new Connection([
                'provider' => Provider::Superhuman,
                'token' => '',
            ]);
        }

        return $connection;
    }

    /**
     * @param  Collection<int, RemoteSource>  $sources
     * @return array<int, array{id: string, name: string}>
     */
    protected function options(Collection $sources): array
    {
        return $sources
            ->map(fn (RemoteSource $source): array => [
                'id' => $source->externalId,
                'name' => $source->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id: string, name: string}>  $options
     */
    protected function optionName(array $options, string $id): string
    {
        foreach ($options as $option) {
            if ($option['id'] === $id) {
                return $option['name'];
            }
        }

        return $id;
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<x-card wire:key="integration-superhuman">
    @if ($this->connection)
        <div data-test="superhuman-connection">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $this->connection->name }}</h3>
                        <x-badge color="gray" light :text="__('Superhuman Docs')" />
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">
                        {{ $this->connection->sources->first()->name ?? __('No doc selected') }}
                        · {{ $this->connection->last_synced_at?->diffForHumans() ?? __('Not synced yet') }}
                    </p>
                </div>

                <div class="flex items-center gap-1">
                    @if ($this->connection->sources->isNotEmpty())
                        <x-button outline sm wire:click="sync" data-test="sync-superhuman" :text="__('Sync')" />
                    @endif
                    <x-button outline sm x-on:click="$tsui.open.modal('disconnect-superhuman-{{ $project->id }}')" data-test="disconnect-superhuman" :text="__('Disconnect')" />
                </div>
            </div>
        </div>

        <div class="mt-6 space-y-4">
            <x-input wire:model="token" type="password" :label="__('API token')" data-test="superhuman-token" />
            <p class="text-sm text-gray-500 dark:text-dark-300">
                {{ __('Paste a new token from docs.superhuman.com/account to replace the one saved on this project.') }}
            </p>
            <x-button outline wire:click="loadDocs" data-test="load-superhuman-docs" :text="__('Update token')" />
        </div>

        <form wire:submit="saveSource" class="mt-6 space-y-4">
            @if ($catalogError !== '')
                <p class="text-sm text-red-600 dark:text-red-400" data-test="superhuman-catalog-error">{{ $catalogError }}</p>
            @endif

            @include('components.projects.partials.superhuman-fields')

            @if ($this->replacingBoard)
                <x-button submit wire:confirm="{{ __('Imported tasks from the previous table will be removed.') }}" data-test="save-superhuman-source" :text="__('Save table')" />
            @else
                <x-button submit data-test="save-superhuman-source" :text="__('Save table')" />
            @endif
        </form>

        <x-modal :id="'disconnect-superhuman-'.$project->id" :title="__('Disconnect :name?', ['name' => $this->connection->name])" center size="lg">
            <form id="disconnect-superhuman-form-{{ $project->id }}" wire:submit="disconnect">
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Synced tasks from this table will be removed.') }}</p>
            </form>
            <x-slot:footer>
                <div class="flex w-full justify-end gap-2">
                    <x-button outline x-on:click="$tsui.close.modal('disconnect-superhuman-{{ $project->id }}')" :text="__('Cancel')" />
                    <x-button submit form="disconnect-superhuman-form-{{ $project->id }}" color="red" data-test="disconnect-superhuman-confirm" :text="__('Disconnect')" />
                </div>
            </x-slot:footer>
        </x-modal>
    @else
        <div class="flex items-center justify-between gap-4">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('Superhuman Docs') }}</h3>
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Import rows assigned to you from a Superhuman table, formerly Coda.') }}</p>
            </div>
            <x-button icon="plus" x-on:click="$tsui.open.modal('connect-superhuman-{{ $project->id }}')" data-test="open-superhuman" :text="__('Connect')" />
        </div>

        <x-modal :id="'connect-superhuman-'.$project->id" :title="__('Connect Superhuman Docs')" center size="lg">
            @if ($errors->isNotEmpty())
                <div x-init="$tsui.open.modal('connect-superhuman-{{ $project->id }}')"></div>
            @endif

            <form id="connect-superhuman-form-{{ $project->id }}" wire:submit="connect" class="space-y-6">
                <p class="text-sm text-gray-500 dark:text-dark-300">
                    {{ __('Create a full-access token at docs.superhuman.com/account on an account that can open the client doc.') }}
                </p>

                <x-input wire:model="name" :label="__('Name')" data-test="superhuman-name" />
                <x-input wire:model="token" type="password" :label="__('API token')" data-test="superhuman-token" />

                <x-button outline wire:click="loadDocs" data-test="load-superhuman-docs" :text="__('Load docs')" />

                @if ($tokenVerified)
                    @if ($catalogError !== '')
                        <p class="text-sm text-red-600 dark:text-red-400" data-test="superhuman-catalog-error">{{ $catalogError }}</p>
                    @endif

                    @include('components.projects.partials.superhuman-fields')
                @endif
            </form>

            <x-slot:footer>
                <div class="flex w-full justify-end gap-2">
                    <x-button outline x-on:click="$tsui.close.modal('connect-superhuman-{{ $project->id }}')" :text="__('Cancel')" />
                    <x-button submit form="connect-superhuman-form-{{ $project->id }}" :disabled="! $tokenVerified" data-test="connect-superhuman" :text="__('Connect')" />
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</x-card>
