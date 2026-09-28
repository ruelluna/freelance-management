<?php

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Enums\Provider;
use App\Models\ConnectedSource;
use App\Models\Project;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Projects')] class extends Component {
    public string $name = '';

    public string $description = '';

    public string $connectedSourceId = '';

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
            'connectedSourceId' => ['nullable', 'uuid'],
        ]);

        $action->handle(
            $this->team(),
            $validated['name'],
            $validated['description'] ?? null,
            $validated['connectedSourceId'] !== '' ? $validated['connectedSourceId'] : null,
        );

        $this->reset('name', 'description', 'connectedSourceId');

        Flux::toast(variant: 'success', text: __('Project created.'));
    }

    public function delete(string $projectId, DeleteProject $action): void
    {
        $project = $this->team()->projects()->findOrFail($projectId);

        Gate::authorize('delete', $project);

        $action->handle($project);

        $this->dispatch('close-modal', name: 'delete-project-'.$projectId);

        Flux::toast(variant: 'success', text: __('Project deleted. Its tasks stay in the inbox.'));
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->team()
            ->projects()
            ->with('connectedSource')
            ->withCount(['issues' => fn ($query) => $query->where('status', 'open')])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, ConnectedSource>
     */
    #[Computed]
    public function githubSources(): Collection
    {
        return ConnectedSource::query()
            ->whereBelongsTo($this->team())
            ->whereHas('connection', fn ($query) => $query->where('provider', Provider::Github))
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
        <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
        <flux:subheading>{{ __('Group tasks into a body of work. Optionally link a GitHub repository.') }}</flux:subheading>
    </div>

    @if ($this->canManage)
        <form wire:submit="create" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:input wire:model="name" :label="__('Name')" data-test="project-name" />
            <flux:textarea wire:model="description" :label="__('Description')" rows="3" data-test="project-description" />
            <flux:select wire:model="connectedSourceId" :label="__('GitHub repository')" data-test="project-repo">
                <flux:select.option value="">{{ __('No repository') }}</flux:select.option>
                @foreach ($this->githubSources as $source)
                    <flux:select.option :value="$source->id">{{ $source->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button variant="primary" type="submit" data-test="create-project">{{ __('Add project') }}</flux:button>
        </form>
    @endif

    <div class="space-y-2">
        @forelse ($this->projects as $project)
            <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="project-{{ $project->id }}" data-test="project-row">
                <div>
                    <a href="{{ route('projects.show', $project) }}" class="font-medium hover:underline" wire:navigate data-test="project-link">
                        {{ $project->name }}
                    </a>
                    <flux:text class="text-sm text-zinc-500">
                        {{ $project->connectedSource->name ?? __('No repository') }}
                        · {{ trans_choice(':count open task|:count open tasks', $project->issues_count) }}
                    </flux:text>
                </div>

                <div class="flex items-center gap-2">
                    <flux:badge :color="$project->status === \App\Enums\ProjectStatus::Open ? 'lime' : 'zinc'">
                        {{ $project->status->label() }}
                    </flux:badge>

                    @if ($this->canManage)
                        <flux:modal.trigger :name="'delete-project-'.$project->id">
                            <flux:button variant="ghost" size="sm" data-test="delete-project">{{ __('Delete') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>
            </div>

            @if ($this->canManage)
                <flux:modal :name="'delete-project-'.$project->id" class="max-w-lg">
                    <form wire:submit="delete('{{ $project->id }}')" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Delete :name?', ['name' => $project->name]) }}</flux:heading>
                            <flux:subheading>{{ __('Tasks in this project stay in the inbox, without a project.') }}</flux:subheading>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button variant="danger" type="submit" data-test="delete-project-confirm">{{ __('Delete') }}</flux:button>
                        </div>
                    </form>
                </flux:modal>
            @endif
        @empty
            <flux:text>{{ __('No projects yet.') }}</flux:text>
        @endforelse
    </div>
</div>
