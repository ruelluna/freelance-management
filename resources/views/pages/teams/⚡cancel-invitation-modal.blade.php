<?php

use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public Team $team;

    public string $invitationCode = '';

    public string $invitationEmail = '';

    public string $modalName = 'cancel-invitation';

    public function mount(
        Team $team,
        ?string $invitationCode = null,
        ?string $invitationEmail = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->invitationCode = $invitationCode ?? '';
        $this->invitationEmail = $invitationEmail ?? '';
        $this->modalName = $modalName ?? ($invitationCode ? "cancel-invitation-{$invitationCode}" : 'cancel-invitation');
    }

    public function cancelInvitation(): void
    {
        $invitation = $this->team->invitations()->where('code', $this->invitationCode)->firstOrFail();

        if ($this->invitationEmail === '') {
            $this->invitationEmail = $invitation->email;
        }

        Gate::authorize('cancelInvitation', $this->team);

        $invitation->delete();

        $this->dispatch('close-modal', name: $this->modalName);

        $this->toast()->success(__('Invitation cancelled.'))->send();

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<x-modal :id="$modalName" :title="__('Cancel invitation')" center size="lg">
    <form id="cancel-invitation-form-{{ $modalName }}" wire:submit="cancelInvitation">
        <p class="text-sm text-gray-500 dark:text-dark-300">
            {{ __('Are you sure you want to cancel the invitation for :email?', ['email' => $invitationEmail]) }}
        </p>
    </form>
    <x-slot:footer>
        <div class="flex w-full justify-end gap-2">
            <x-button outline x-on:click="$tsui.close.modal('{{ $modalName }}')" :text="__('Keep invitation')" />
            <x-button submit form="cancel-invitation-form-{{ $modalName }}" color="red" data-test="cancel-invitation-confirm" :text="__('Cancel invitation')" />
        </div>
    </x-slot:footer>
</x-modal>
