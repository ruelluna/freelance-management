<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public Team $team;

    public ?int $memberId = null;

    public string $memberName = '';

    public string $modalName = 'remove-member';

    public function mount(
        Team $team,
        ?int $memberId = null,
        ?string $memberName = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->memberId = $memberId;
        $this->memberName = $memberName ?? '';
        $this->modalName = $modalName ?? ($memberId ? "remove-member-{$memberId}" : 'remove-member');
    }

    public function removeMember(): void
    {
        Gate::authorize('removeMember', $this->team);

        $user = User::findOrFail($this->memberId);

        if ($this->memberName === '') {
            $this->memberName = $user->name;
        }

        $this->team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($this->team)) {
            $user->switchTeam($user->personalTeam());
        }

        $this->dispatch('close-modal', name: $this->modalName);

        $this->toast()->success(__('Member removed.'))->send();

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<x-modal :id="$modalName" :title="__('Remove team member')" center size="lg">
    <form id="remove-member-form-{{ $modalName }}" wire:submit="removeMember">
        <p class="text-sm text-gray-500 dark:text-dark-300">
            {{ __('Are you sure you want to remove :name from this team?', ['name' => $memberName]) }}
        </p>
    </form>
    <x-slot:footer>
        <div class="flex w-full justify-end gap-2">
            <x-button outline x-on:click="$tsui.close.modal('{{ $modalName }}')" :text="__('Cancel')" />
            <x-button submit form="remove-member-form-{{ $modalName }}" color="red" data-test="remove-member-confirm" :text="__('Remove member')" />
        </div>
    </x-slot:footer>
</x-modal>
