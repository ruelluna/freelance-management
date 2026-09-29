<?php

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Models\Client;
use App\Models\Project;
use App\Models\Team;
use App\Services\TeamResourceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Projects')] class extends Component {
    use Interactions;

    public string $name = '';

    public string $description = '';

    public string $clientId = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', [Project::class, $this->team()]);
    }

    public function create(CreateProject $action): void
    {
        Gate::authorize('create', [Project::class, $this->team()]);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'clientId' => ['nullable', 'uuid'],
        ]);

        $action->handle(
            $this->team(),
            $validated['name'],
            $validated['description'] ?? null,
            $validated['clientId'] !== '' ? $validated['clientId'] : null,
            Auth::user(),
        );

        $this->reset('name', 'description', 'clientId');

        $this->toast()->success(__('Project created.'))->send();
    }

    public function delete(string $projectId, DeleteProject $action): void
    {
        $project = TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeProjects($this->team()->projects())
            ->findOrFail($projectId);

        Gate::authorize('delete', $project);

        $action->handle($project);

        $this->dispatch('close-modal', name: 'delete-project-'.$projectId);

        $this->toast()->success(__('Project deleted. Its tasks stay in the inbox.'))->send();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        $relations = ['client'];

        if (! Auth::user()->isTeamClient($this->team())) {
            $relations[] = 'connectedSource';
        }

        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeProjects($this->team()->projects())
            ->with($relations)
            ->withCount(['issues' => function ($query) {
                TeamResourceAccess::for(Auth::user(), $this->team())
                    ->scopeIssues($query->where('status', 'open'));
            }])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeClients($this->team()->clients()->where('status', 'active'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('create', [Project::class, $this->team()]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Projects') }}</h1>
        <p class="text-sm text-gray-500 dark:text-dark-300">{{ Auth::user()->isScopedTeamUser($this->team()) ? __('Projects shared with you.') : __('Group tasks into a body of work.') }}</p>
    </div>

    @if ($this->canManage)
        <x-card>
            <form wire:submit="create" class="space-y-4">
                <x-input wire:model="name" :label="__('Name')" data-test="project-name" />
                <livewire:markdown-editor wire:model.live="description" :label="__('Description')" min-height="6rem" test-id="project-description" />
                <x-select.native wire:model="clientId" :label="__('Client')" data-test="project-client">
                    <option value="">{{ __('No client') }}</option>
                    @foreach ($this->clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </x-select.native>
                <x-button submit data-test="create-project" :text="__('Add project')" />
            </form>
        </x-card>
    @endif

    <div class="space-y-2">
        @forelse ($this->projects as $project)
            <x-card wire:key="project-{{ $project->id }}">
                <div class="flex items-center justify-between gap-4" data-test="project-row">
                    <div>
                        <a href="{{ route(auth()->user()->sectionRoute('projects.show'), $project) }}" class="font-medium hover:underline" wire:navigate data-test="project-link">
                            {{ $project->name }}
                        </a>
                        <p class="text-sm text-gray-500 dark:text-dark-300">
                            {{ $project->client->name ?? __('Internal') }}
                            @unless (auth()->user()->isTeamClient(auth()->user()->currentTeam))
                                · {{ $project->connectedSource->name ?? __('No repository') }}
                            @endunless
                            · {{ trans_choice(':count open task|:count open tasks', $project->issues_count) }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-badge light :color="$project->status === \App\Enums\ProjectStatus::Open ? 'green' : 'gray'" :text="$project->status->label()" />

                        @if ($this->canManage)
                            <x-button outline sm x-on:click="$tsui.open.modal('delete-project-{{ $project->id }}')" data-test="delete-project" :text="__('Delete')" />
                        @endif
                    </div>
                </div>
            </x-card>

            @if ($this->canManage)
                <x-modal :id="'delete-project-'.$project->id" :title="__('Delete :name?', ['name' => $project->name])" center size="lg">
                    <form id="delete-project-form-{{ $project->id }}" wire:submit="delete('{{ $project->id }}')">
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Tasks in this project stay in the inbox, without a project.') }}</p>
                    </form>
                    <x-slot:footer>
                        <div class="flex w-full justify-end gap-2">
                            <x-button outline x-on:click="$tsui.close.modal('delete-project-{{ $project->id }}')" :text="__('Cancel')" />
                            <x-button submit form="delete-project-form-{{ $project->id }}" color="red" data-test="delete-project-confirm" :text="__('Delete')" />
                        </div>
                    </x-slot:footer>
                </x-modal>
            @endif
        @empty
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ Auth::user()->isScopedTeamUser($this->team()) ? __('No projects assigned to you yet.') : __('No projects yet.') }}</p>
        @endforelse
    </div>
</div>
