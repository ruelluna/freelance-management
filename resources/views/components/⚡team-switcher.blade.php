<?php

use App\Data\UserTeam;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function currentTeam(): ?array
    {
        $team = Auth::user()->currentTeam;

        return $team ? [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
        ] : null;
    }

    /**
     * @return Collection<int, UserTeam>
     */
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }

    public function switchTeam(string $slug): void
    {
        $user = Auth::user();

        abort_unless(
            $user->belongsToTeam($team = Team::where('slug', $slug)->firstOrFail()),
            403
        );

        $currentTeamSlug = $user->currentTeam?->slug;

        $user->switchTeam($team);

        if (! request()->header('Referer')) {
            $this->redirectRoute($user->homeRoute(), navigate: true);

            return;
        }

        if (! $currentTeamSlug) {
            $this->redirect(request()->header('Referer'), navigate: true);

            return;
        }

        $redirectTo = $this->replaceCurrentTeamInReferer(
            request()->header('Referer'),
            $currentTeamSlug,
            $team->slug,
        );

        $this->redirect($redirectTo ?? request()->header('Referer'), navigate: true);
    }

    protected function replaceCurrentTeamInReferer(string $referer, string $currentTeamSlug, string $newTeamSlug): ?string
    {
        $redirectTo = preg_replace(
            '#/'.preg_quote($currentTeamSlug, '#').'(?=/|\?|$)#',
            '/'.$newTeamSlug,
            $referer,
            1,
        );

        return preg_replace(
            '#([?&]current_team=)'.preg_quote($currentTeamSlug, '#').'(?=&|$)#',
            '$1'.$newTeamSlug,
            $redirectTo ?? $referer,
            1,
        );
    }
}; ?>

<div class="w-full [&>div]:w-full [&>div>div]:w-full">
    <x-dropdown position="bottom-start" width="md" x-on:select="show = false">
        <x-slot:action>
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-dark-700"
                data-test="team-switcher-trigger"
                x-on:click="show = !show"
            >
                <x-icon name="users" class="size-4 shrink-0 text-gray-500" />
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $this->currentTeam()['name'] ?? __('Select team') }}</span>
                <x-icon name="chevron-up-down" class="ms-auto size-4 shrink-0 text-gray-400" />
            </button>
        </x-slot:action>

        <x-slot:header>
            <p class="px-2 text-xs font-medium text-gray-500 dark:text-dark-300">{{ __('Teams') }}</p>
        </x-slot:header>

        @foreach ($this->teams() as $team)
            <x-dropdown.items wire:click="switchTeam('{{ $team->slug }}')" data-test="team-switcher-item">
                <span class="flex w-full items-center justify-between gap-3">
                    <span class="truncate">{{ $team->name }}</span>
                    @if ($team->isCurrent)
                        <x-icon name="check" class="size-4 shrink-0" />
                    @endif
                </span>
            </x-dropdown.items>
        @endforeach

        @can('create', App\Models\Team::class)
            <x-dropdown.items
                icon="plus"
                separator
                data-test="team-switcher-new-team"
                x-on:click="$tsui.open.modal('create-team-switcher'); $refs.dropdown.dispatchEvent(new CustomEvent('select'))"
            >
                {{ __('New team') }}
            </x-dropdown.items>
        @endcan
    </x-dropdown>
</div>
