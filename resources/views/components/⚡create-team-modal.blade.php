<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Team;
use App\Rules\TeamName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public string $teamName = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        Gate::authorize('create', Team::class);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['teamName']);

        $this->dispatch('close-modal', name: 'create-team-switcher');

        $this->reset('teamName');

        $this->toast()->success(__('Team created.'))->send();

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
    }
}; ?>

<div>
    <x-modal id="create-team-switcher" :title="__('Create a new team')" center size="lg">
        <form id="create-team-switcher-form" wire:submit="createTeam" class="space-y-6">
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Give your team a name to get started.') }}</p>

            <x-input wire:model="teamName" :label="__('Team name')" type="text" required autofocus data-test="switcher-create-team-name" />
        </form>

        <x-slot:footer>
            <x-button outline :text="__('Cancel')" x-on:click="$tsui.close.modal('create-team-switcher')" />

            <x-button submit :text="__('Create team')" form="create-team-switcher-form" data-test="switcher-create-team-submit" />
        </x-slot:footer>
    </x-modal>

    @if ($errors->isNotEmpty())
        <div x-init="$tsui.open.modal('create-team-switcher')"></div>
    @endif
</div>
