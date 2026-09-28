<?php

use App\Actions\Clients\ArchiveClient;
use App\Actions\Clients\DeleteClient;
use App\Actions\Clients\InviteClientUser;
use App\Actions\Clients\UpdateClient;
use App\Actions\Projects\UpdateProject;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Client')] class extends Component {
    use Interactions;

    public Client $client;

    public string $name = '';

    public string $contactEmail = '';

    public string $notes = '';

    public string $inviteEmail = '';

    public string $assignProjectId = '';

    public function mount(Client $client): void
    {
        abort_unless($client->team_id === $this->team()->id, 404);

        Gate::authorize('view', $client);

        $this->client = $client;
        $this->fillFromClient();
    }

    public function save(UpdateClient $action): void
    {
        Gate::authorize('update', $this->client);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ]);

        $this->client = $action->handle(
            $this->client,
            $validated['name'],
            $validated['contactEmail'] ?? null,
            $validated['notes'] ?? null,
        );

        $this->fillFromClient();

        $this->toast()->success(__('Client saved.'))->send();

        $this->redirectRoute('clients.show', ['client' => $this->client->fresh()->id], navigate: true);
    }

    public function invite(InviteClientUser $action): void
    {
        Gate::authorize('inviteUser', $this->client);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255'],
        ]);

        $action->handle($this->client, $validated['inviteEmail']);

        $this->reset('inviteEmail');

        $this->toast()->success(__('Invitation sent.'))->send();

        unset($this->pendingInvitations);
    }

    public function assignProject(UpdateProject $action): void
    {
        Gate::authorize('update', $this->client);

        $validated = $this->validate([
            'assignProjectId' => ['required', 'uuid'],
        ]);

        $project = $this->team()->projects()->findOrFail($validated['assignProjectId']);

        $action->handle(
            $project,
            $project->name,
            $project->description,
            $project->status,
            $this->client->id,
        );

        $this->reset('assignProjectId');

        $this->toast()->success(__('Project assigned to client.'))->send();

        unset($this->assignedProjects, $this->unassignedProjects);
    }

    public function unassignProject(string $projectId, UpdateProject $action): void
    {
        Gate::authorize('update', $this->client);

        $project = $this->client->projects()->findOrFail($projectId);

        $action->handle(
            $project,
            $project->name,
            $project->description,
            $project->status,
            null,
        );

        $this->toast()->success(__('Project unassigned from client.'))->send();

        unset($this->assignedProjects, $this->unassignedProjects);
    }

    public function archive(ArchiveClient $action): void
    {
        Gate::authorize('update', $this->client);

        $this->client = $action->handle($this->client);

        $this->toast()->success(__('Client archived.'))->send();

        unset($this->assignedProjects);
    }

    public function delete(DeleteClient $action): void
    {
        Gate::authorize('delete', $this->client);

        $action->handle($this->client);

        $this->redirect(route('clients.index'), navigate: true);
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function assignedProjects(): Collection
    {
        return $this->client->projects()
            ->withCount(['issues' => fn ($query) => $query->where('status', 'open')])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function unassignedProjects(): Collection
    {
        return $this->team()
            ->projects()
            ->whereNull('client_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function users(): Collection
    {
        return $this->client->users()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, TeamInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->client->team->invitations()
            ->where('client_id', $this->client->id)
            ->whereNull('accepted_at')
            ->latest()
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('update', $this->client);
    }

    protected function fillFromClient(): void
    {
        $this->name = $this->client->name;
        $this->contactEmail = $this->client->contact_email ?? '';
        $this->notes = $this->client->notes ?? '';
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('clients.index') }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to clients') }}</a>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $client->name }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">
                {{ $client->contact_email ?? __('No contact email') }}
            </p>
        </div>

        <x-badge light data-test="client-status" :color="$client->status === ClientStatus::Active ? 'green' : 'gray'" :text="$client->status->label()" />
    </div>

    @if ($this->canManage)
        <x-card>
            <form wire:submit="save" class="space-y-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit client') }}</h2>
                <x-input wire:model="name" :label="__('Name')" data-test="edit-client-name" />
                <x-input wire:model="contactEmail" type="email" :label="__('Contact email')" data-test="edit-client-contact-email" />
                <x-editor markdown wire:model="notes" :label="__('Internal notes')" min-height="6rem" data-test="edit-client-notes" />
                <div class="flex flex-wrap gap-2">
                    <x-button submit data-test="save-client" :text="__('Save client')" />
                    @if ($client->status === ClientStatus::Active)
                        <x-button outline wire:click="archive" wire:confirm="{{ __('Archive this client? Their users will lose access.') }}" data-test="archive-client" :text="__('Archive')" />
                    @endif
                    <x-button outline x-on:click="$tsui.open.modal('delete-client')" data-test="delete-client" :text="__('Delete')" />
                </div>
            </form>
        </x-card>

        <x-modal id="delete-client" :title="__('Delete :name?', ['name' => $client->name])" center size="lg">
            <form id="delete-client-form" wire:submit="delete">
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Projects stay on the team but are unassigned from this client.') }}</p>
            </form>
            <x-slot:footer>
                <div class="flex w-full justify-end gap-2">
                    <x-button outline x-on:click="$tsui.close.modal('delete-client')" :text="__('Cancel')" />
                    <x-button submit form="delete-client-form" color="red" data-test="delete-client-confirm" :text="__('Delete')" />
                </div>
            </x-slot:footer>
        </x-modal>

        @if ($client->status === ClientStatus::Active)
            <x-card>
                <form wire:submit="invite" class="space-y-4">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Invite client user') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Send an invitation for someone to access this client\'s projects.') }}</p>
                    <x-input wire:model="inviteEmail" type="email" :label="__('Email address')" data-test="client-invite-email" />
                    <x-button submit data-test="client-invite-submit" :text="__('Send invitation')" />
                </form>
            </x-card>
        @endif
    @endif

    <x-card>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Users') }}</h2>
        <div class="mt-4 space-y-2">
            @forelse ($this->users as $user)
                <div class="flex items-center justify-between rounded-lg px-2 py-2" wire:key="client-user-{{ $user->id }}" data-test="client-user">
                    <div>
                        <span>{{ $user->name }}</span>
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ $user->email }}</p>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No users linked yet.') }}</p>
            @endforelse

            @foreach ($this->pendingInvitations as $invitation)
                <div class="flex items-center justify-between rounded-lg border border-dashed border-zinc-300 px-2 py-2 dark:border-dark-600" wire:key="client-invitation-{{ $invitation->id }}" data-test="client-pending-invitation">
                    <p class="text-sm text-gray-500 dark:text-dark-300">{{ $invitation->email }} · {{ __('Pending') }}</p>
                </div>
            @endforeach
        </div>
    </x-card>

    <x-card>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Assigned projects') }}</h2>
        <div class="mt-4 space-y-2">
            @forelse ($this->assignedProjects as $project)
                <div class="flex items-center justify-between gap-4 rounded-lg px-2 py-2" wire:key="assigned-project-{{ $project->id }}" data-test="assigned-project">
                    <div>
                        <a href="{{ route('projects.show', $project) }}" class="font-medium hover:underline" wire:navigate>{{ $project->name }}</a>
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ trans_choice(':count open task|:count open tasks', $project->issues_count) }}</p>
                    </div>
                    @if ($this->canManage)
                        <x-button outline sm wire:click="unassignProject('{{ $project->id }}')" data-test="unassign-project" :text="__('Unassign')" />
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No projects assigned yet.') }}</p>
            @endforelse
        </div>
    </x-card>

    @if ($this->canManage && $client->status === ClientStatus::Active)
        <x-card>
            <form wire:submit="assignProject" class="space-y-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Assign project') }}</h2>
                <x-select.native wire:model="assignProjectId" :label="__('Project')" data-test="assign-project-select">
                    <option value="">{{ __('Choose a project') }}</option>
                    @foreach ($this->unassignedProjects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </x-select.native>
                <x-button submit data-test="assign-project-submit" :text="__('Assign project')" />
            </form>
        </x-card>
    @endif
</div>
