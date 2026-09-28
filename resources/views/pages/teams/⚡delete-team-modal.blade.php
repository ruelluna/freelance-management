<?php

use App\Data\UserTeam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public Team $team;

    public string $deleteName = '';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    #[Computed]
    public function deleteConfirmLabel(): string
    {
        return __('Type ":name" to confirm', ['name' => $this->team->name]);
    }

    public function deleteTeam(): void
    {
        Gate::authorize('delete', $this->team);

        $validated = $this->validate([
            'deleteName' => ['required', 'string'],
        ]);

        if ($validated['deleteName'] !== $this->team->name) {
            $this->addError('deleteName', __('The team name does not match.'));

            return;
        }

        $user = Auth::user();

        $fallbackTeam = $user->isCurrentTeam($this->team)
            ? $user->fallbackTeam($this->team)
            : null;

        DB::transaction(function () use ($user) {
            User::where('current_team_id', $this->team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchTeam($affectedUser->personalTeam()));

            $this->team->invitations()->delete();
            $this->team->memberships()->delete();
            $this->team->delete();
        });

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        $this->toast()->success(__('Team deleted.'))->send();

        $this->redirectRoute('teams.index', navigate: true);
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function otherTeams(): Collection
    {
        return Auth::user()->toUserTeams();
    }
}; ?>

<x-modal id="delete-team" :title="__('Are you sure?')" center size="lg">
    @if ($errors->isNotEmpty())
        <div x-init="$tsui.open.modal('delete-team')"></div>
    @endif

    <form id="delete-team-form" wire:submit="deleteTeam" class="space-y-6">
        <p class="text-sm text-gray-500 dark:text-dark-300">
            {{ __('This action cannot be undone. This will permanently delete the team ":name".', ['name' => $team->name]) }}
        </p>

        <x-input wire:model="deleteName" :label="$this->deleteConfirmLabel" required data-test="delete-team-name" />
    </form>

    <x-slot:footer>
        <div class="flex w-full justify-end gap-2">
            <x-button outline x-on:click="$tsui.close.modal('delete-team')" :text="__('Cancel')" />
            <x-button submit form="delete-team-form" color="red" data-test="delete-team-confirm" :text="__('Delete team')" />
        </div>
    </x-slot:footer>
</x-modal>
