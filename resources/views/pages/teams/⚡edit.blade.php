<?php

use App\Data\TeamPermissions;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Rules\TeamName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component
{
    use Interactions;

    public Team $teamModel;

    public string $teamName = '';

    public array $teamData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public bool $isCurrentTeam = false;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->teamName = $team->name;

        $this->populateTeamData();
    }

    public function updateTeam(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = DB::transaction(function () use ($validated) {
            $team = Team::whereKey($this->teamModel->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $validated['teamName']]);

            return $team;
        });

        $this->teamModel = $team;

        $this->populateTeamData();

        $this->toast()->success(__('Team updated.'))->send();

        $this->redirectRoute('teams.edit', ['team' => $this->teamModel->fresh()->slug], navigate: true);
    }

    public function updateMember(int $userId, string $role): void
    {
        Gate::authorize('updateMember', $this->teamModel);

        $validated = Validator::make(['role' => $role], [
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ])->validate();

        $this->teamModel->memberships()
            ->where('user_id', $userId)
            ->firstOrFail()
            ->update(['role' => TeamRole::from($validated['role'])]);

        $this->populateTeamData();

        $this->toast()->success(__('Member role updated.'))->send();
    }

    private function populateTeamData(): void
    {
        $user = Auth::user();

        $team = $this->teamModel->fresh();

        $this->teamData = [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'is_personal' => $team->is_personal,
        ];

        $this->members = $team->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'initials' => $member->initials(),
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
        ])->toArray();

        $this->invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = TeamRole::assignable();

        $this->isCurrentTeam = $user->isCurrentTeam($team);
    }

    public function render()
    {
        $teamName = $this->teamData['name'] ?? $this->teamModel->name;

        $title = $this->permissions->canUpdateTeam
            ? __('Edit :name', ['name' => $teamName])
            : __('View :name', ['name' => $teamName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h1 class="sr-only">{{ __('Teams') }}</h1>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your team settings')">
        <div class="space-y-10">
            <div class="space-y-6">
                @if ($this->permissions->canUpdateTeam)
                    <div class="space-y-4">
                        <form wire:submit="updateTeam" class="space-y-6">
                            <x-input wire:model="teamName" :label="__('Team name')" required data-test="team-name-input" />

                            <x-button submit data-test="team-save-button" :text="__('Save')" />
                        </form>
                    </div>
                @else
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $teamData['name'] }}</h2>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Team members') }}</h2>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Manage who belongs to this team') }}</p>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <x-button icon="user-plus" x-on:click="$tsui.open.modal('invite-member')" data-test="invite-member-button" :text="__('Invite member')" />
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <x-card>
                            <div class="flex items-center justify-between" data-test="member-row">
                                <div class="flex items-center gap-4">
                                    <x-avatar sm :text="$member['initials']" />
                                    <div>
                                        <div class="font-medium">{{ $member['name'] }}</div>
                                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ $member['email'] }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    @if ($member['role'] !== 'owner' && $this->permissions->canUpdateMember)
                                        <x-dropdown position="bottom-end">
                                            <x-slot:action>
                                                <x-button outline sm position="right" icon="chevron-down" data-test="member-role-trigger" x-on:click="show = ! show" :text="$member['role_label']" />
                                            </x-slot:action>

                                            @foreach ($availableRoles as $role)
                                                <x-dropdown.items
                                                    :text="$role['label']"
                                                    wire:click="updateMember({{ $member['id'] }}, '{{ $role['value'] }}')"
                                                    data-test="member-role-option"
                                                />
                                            @endforeach
                                        </x-dropdown>
                                    @else
                                        <x-badge color="gray" light :text="$member['role_label']" />
                                    @endif

                                    @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                                        <x-button
                                            outline
                                            sm
                                            icon="x-mark"
                                            :tooltip="__('Remove member')"
                                            x-on:click="$tsui.open.modal('remove-member-{{ $member['id'] }}')"
                                            data-test="member-remove-button"
                                        />
                                    @endif
                                </div>
                            </div>
                        </x-card>

                        @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                            <livewire:pages::teams.remove-member-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Pending invitations') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Invitations that have not been accepted yet') }}</p>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <x-card>
                                <div class="flex items-center justify-between" data-test="invitation-row">
                                    <div class="flex items-center gap-4">
                                        <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-dark-700">
                                            <x-icon name="envelope" class="text-gray-500" />
                                        </div>
                                        <div>
                                            <div class="font-medium">{{ $invitation['email'] }}</div>
                                            <p class="text-sm text-gray-500 dark:text-dark-300">{{ $invitation['role_label'] }}</p>
                                        </div>
                                    </div>

                                    @if ($this->permissions->canCancelInvitation)
                                        <x-button
                                            outline
                                            sm
                                            icon="x-mark"
                                            :tooltip="__('Cancel invitation')"
                                            x-on:click="$tsui.open.modal('cancel-invitation-{{ $invitation['code'] }}')"
                                            data-test="invitation-cancel-button"
                                        />
                                    @endif
                                </div>
                            </x-card>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::teams.cancel-invitation-modal
                                    :team="$teamModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Delete team') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Permanently delete your team') }}</p>
                    </div>

                    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
                        <div>
                            <p class="font-medium">{{ __('Warning') }}</p>
                            <p class="text-sm">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <x-button color="red" x-on:click="$tsui.open.modal('delete-team')" data-test="delete-team-button" :text="__('Delete team')" />
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::teams.invite-member-modal :team="$teamModel" />
    @endif

    @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
        <livewire:pages::teams.delete-team-modal :team="$teamModel" />
    @endif
</section>
