<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public Team $team;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function createInvitation(): void
    {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
            'inviteRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => TeamRole::from($validated['inviteRole']),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        try {
            Notification::route('mail', $invitation->email)
                ->notify(new TeamInvitationNotification($invitation));
        } catch (\Throwable $exception) {
            $invitation->delete();

            Log::error('Team invitation email failed', [
                'team_id' => $this->team->id,
                'email' => $validated['inviteEmail'],
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'inviteEmail' => [__('We could not send the invitation email. Please try again.')],
            ]);
        }

        $this->reset('inviteEmail', 'inviteRole');
        $this->dispatch('close-modal', name: 'invite-member');

        $this->toast()->success(__('Invitation sent.'))->send();

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }
}; ?>

<x-modal id="invite-member" :title="__('Invite a team member')" center size="lg">
    @if ($errors->isNotEmpty())
        <div x-init="$tsui.open.modal('invite-member')"></div>
    @endif

    <form id="invite-member-form" wire:submit="createInvitation" class="space-y-6">
        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Send an invitation to join this team.') }}</p>

        <div class="space-y-4">
            <x-input wire:model="inviteEmail" type="email" :label="__('Email address')" required data-test="invite-email" />

            <x-select.native wire:model="inviteRole" :label="__('Role')" data-test="invite-role">
                @foreach ($this->availableRoles as $role)
                    <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                @endforeach
            </x-select.native>
        </div>
    </form>

    <x-slot:footer>
        <div class="flex w-full justify-end gap-2">
            <x-button outline x-on:click="$tsui.close.modal('invite-member')" :text="__('Cancel')" />
            <x-button submit form="invite-member-form" data-test="invite-submit" :text="__('Send invitation')" />
        </div>
    </x-slot:footer>
</x-modal>
