<?php

use App\Actions\Teams\CreateTeam;
use App\Data\UserTeam;
use App\Models\Team;
use App\Rules\TeamName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Title('Teams')] class extends Component {
    use Interactions;

    public string $name = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        Gate::authorize('create', Team::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['name']);

        $this->dispatch('close-modal', name: 'create-team');

        $this->reset('name');

        $this->toast()->success(__('Team created.'))->send();

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
    }

    public function leaveTeam(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $user = Auth::user();

        Gate::authorize('leave', $team);

        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        $this->dispatch('close-modal', name: "leave-team-{$teamId}");

        $this->toast()->success(__('You left the team ":name"', ['name' => $team->name]))->send();

        $this->redirectRoute('teams.index', navigate: true);
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h1 class="sr-only">{{ __('Teams') }}</h1>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your teams and team memberships')">
        @can('create', App\Models\Team::class)
            <div class="flex items-center justify-end">
                <x-button icon="plus" x-on:click="$tsui.open.modal('create-team')" data-test="teams-new-team-button" :text="__('New team')" />
            </div>
        @endcan

        <div class="mt-6 space-y-3">
            @forelse ($this->teams as $team)
                <x-card>
                    <div class="flex items-center justify-between gap-4" data-test="team-row">
                        <div class="flex items-center gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $team->name }}</span>
                                    @if ($team->isPersonal)
                                        <x-badge color="gray" light :text="__('Personal')" />
                                    @endif
                                </div>
                                <p class="text-sm text-gray-500 dark:text-dark-300">{{ $team->roleLabel }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-1">
                            @if (! $team->isPersonal && $team->role !== 'owner')
                                <x-button
                                    outline
                                    sm
                                    icon="arrow-right-start-on-rectangle"
                                    :tooltip="__('Leave team')"
                                    x-on:click="$tsui.open.modal('leave-team-{{ $team->id }}')"
                                    data-test="team-leave-button"
                                />
                            @endif

                            <x-button
                                outline
                                sm
                                :icon="$team->role === 'member' ? 'eye' : 'pencil'"
                                :href="route('teams.edit', $team->slug)"
                                wire:navigate
                                :tooltip="$team->role === 'member' ? __('View team') : __('Edit team')"
                                :data-test="$team->role === 'member' ? 'team-view-button' : 'team-edit-button'"
                            />
                        </div>
                    </div>
                </x-card>

                @if (! $team->isPersonal && $team->role !== 'owner')
                    <x-modal :id="'leave-team-'.$team->id" :title="__('Leave team')" center size="lg">
                        <form id="leave-team-form-{{ $team->id }}" wire:submit="leaveTeam({{ $team->id }})">
                            <p class="text-sm text-gray-500 dark:text-dark-300">
                                {{ __('Are you sure you want to leave :name?', ['name' => $team->name]) }}
                            </p>
                        </form>
                        <x-slot:footer>
                            <div class="flex w-full justify-end gap-2">
                                <x-button outline x-on:click="$tsui.close.modal('leave-team-{{ $team->id }}')" :text="__('Cancel')" />
                                <x-button submit form="leave-team-form-{{ $team->id }}" color="red" data-test="leave-team-confirm" :text="__('Leave team')" />
                            </div>
                        </x-slot:footer>
                    </x-modal>
                @endif
            @empty
                <p class="py-8 text-center text-sm text-gray-500 dark:text-dark-300">
                    {{ __('You don\'t belong to any teams yet.') }}
                </p>
            @endforelse
        </div>
    </x-pages::settings.layout>

    @can('create', App\Models\Team::class)
    <x-modal id="create-team" :title="__('Create a new team')" center size="lg">
        @if ($errors->isNotEmpty())
            <div x-init="$tsui.open.modal('create-team')"></div>
        @endif

        <form id="create-team-form" wire:submit="createTeam" class="space-y-6">
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Give your team a name to get started.') }}</p>
            <x-input wire:model="name" :label="__('Team name')" type="text" required autofocus data-test="create-team-name" />
        </form>
        <x-slot:footer>
            <div class="flex w-full justify-end gap-2">
                <x-button outline x-on:click="$tsui.close.modal('create-team')" :text="__('Cancel')" />
                <x-button submit form="create-team-form" data-test="create-team-submit" :text="__('Create team')" />
            </div>
        </x-slot:footer>
    </x-modal>
    @endcan
</section>
