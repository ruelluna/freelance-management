<?php

use App\Actions\Projects\AssignProjectMembers;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Enums\ProjectStatus;
use App\Enums\TeamRole;
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

new #[Layout('layouts::app')] #[Title('Edit project')] class extends Component {
    use Interactions;

    public Project $project;

    public string $name = '';

    public string $description = '';

    public string $clientId = '';

    /**
     * @var array<int, int|string>
     */
    public array $memberIds = [];

    public function mount(Project $project): void
    {
        abort_unless($project->team_id === $this->team()->id, 404);

        Gate::authorize('update', $project);

        $this->project = $project->load(['client', 'members']);
        $this->fillFromProject();
    }

    public function saveMembers(AssignProjectMembers $action): void
    {
        Gate::authorize('update', $this->project);

        $validated = $this->validate([
            'memberIds' => ['array'],
            'memberIds.*' => ['integer'],
        ]);

        $this->project = $action->handle($this->project, $validated['memberIds']);

        $this->memberIds = $this->project->members->pluck('id')->all();

        $this->toast()->success(__('Project members updated.'))->send();
    }

    public function save(UpdateProject $action): void
    {
        Gate::authorize('update', $this->project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'clientId' => ['nullable', 'uuid'],
        ]);

        $this->project = $action->handle(
            $this->project,
            $validated['name'],
            $validated['description'] ?? null,
            $this->project->status,
            $validated['clientId'] !== '' ? $validated['clientId'] : null,
            Auth::user(),
        );

        $this->fillFromProject();

        $this->toast()->success(__('Project saved.'))->send();
    }

    public function toggleStatus(UpdateProject $action): void
    {
        Gate::authorize('update', $this->project);

        $next = $this->project->status === ProjectStatus::Open
            ? ProjectStatus::Closed
            : ProjectStatus::Open;

        $this->project = $action->handle(
            $this->project,
            $this->project->name,
            $this->project->description,
            $next,
            $this->project->client_id,
        );

        $this->toast()->success(__('Project :status.', ['status' => $next->label()]))->send();
    }

    public function delete(DeleteProject $action): void
    {
        Gate::authorize('delete', $this->project);

        $action->handle($this->project);

        $this->redirect(route(Auth::user()->sectionRoute('projects.index')), navigate: true);
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

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function teamMembers(): Collection
    {
        return $this->team()
            ->members()
            ->wherePivot('role', TeamRole::Member->value)
            ->orderBy('name')
            ->get();
    }

    protected function fillFromProject(): void
    {
        $this->name = $this->project->name;
        $this->description = $this->project->description ?? '';
        $this->clientId = $this->project->client_id ?? '';
        $this->memberIds = $this->project->members->pluck('id')->all();
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route(auth()->user()->sectionRoute('projects.show'), $project) }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to project') }}</a>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Edit project') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">{{ $project->name }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-badge light data-test="project-status" :color="$project->status === \App\Enums\ProjectStatus::Open ? 'green' : 'gray'" :text="$project->status->label()" />
            <x-button outline wire:click="toggleStatus" data-test="toggle-project-status" :text="$project->status === \App\Enums\ProjectStatus::Open ? __('Close') : __('Reopen')" />
            <x-button outline x-on:click="$tsui.open.modal('delete-project')" data-test="delete-project-page" :text="__('Delete')" />
        </div>
    </div>

    <x-card>
        <form wire:submit="save" class="space-y-4">
            <x-input wire:model="name" :label="__('Name')" data-test="edit-project-name" />
            <livewire:markdown-editor wire:model="description" :label="__('Description')" min-height="6rem" test-id="edit-project-description" />
            <x-select.native wire:model="clientId" :label="__('Client')" data-test="edit-project-client">
                <option value="">{{ __('No client') }}</option>
                @foreach ($this->clients as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </x-select.native>
            <x-button submit data-test="save-project" :text="__('Save project')" />
        </form>
    </x-card>

    <x-modal id="delete-project" :title="__('Delete :name?', ['name' => $project->name])" center size="lg">
        <form id="delete-project-form" wire:submit="delete">
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Tasks in this project stay in the inbox, without a project.') }}</p>
        </form>
        <x-slot:footer>
            <div class="flex w-full justify-end gap-2">
                <x-button outline x-on:click="$tsui.close.modal('delete-project')" :text="__('Cancel')" />
                <x-button submit form="delete-project-form" color="red" data-test="delete-project-page-confirm" :text="__('Delete')" />
            </div>
        </x-slot:footer>
    </x-modal>

    <x-card>
        <form wire:submit="saveMembers" class="space-y-4">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Team members') }}</h2>
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Members assigned here can see every task in this project.') }}</p>

            @if ($this->teamMembers->isEmpty())
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No team members with the Member role are available.') }}</p>
            @else
                <div class="space-y-2">
                    @foreach ($this->teamMembers as $member)
                        <label class="flex items-center gap-2 text-sm" wire:key="project-member-{{ $member->id }}">
                            <input type="checkbox" value="{{ $member->id }}" wire:model="memberIds" data-test="project-member">
                            <span>{{ $member->name }}</span>
                            <p class="text-sm text-gray-500 dark:text-dark-300">{{ $member->email }}</p>
                        </label>
                    @endforeach
                </div>
                <x-button submit data-test="save-project-members" :text="__('Save members')" />
            @endif
        </form>
    </x-card>
</div>
