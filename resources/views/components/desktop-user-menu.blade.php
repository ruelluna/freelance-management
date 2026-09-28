@props(['showTeam' => true])

<x-dropdown position="bottom-end" width="md">
    <x-slot:action>
        <button type="button" class="flex items-center gap-2 rounded-lg p-1 text-start hover:bg-gray-100 dark:hover:bg-dark-700" data-test="sidebar-menu-button" x-on:click="show = !show">
            <x-avatar :text="auth()->user()->initials()" xs />
            <span class="hidden min-w-0 sm:block">
                <span class="block truncate text-sm font-medium text-gray-800 dark:text-white">{{ auth()->user()->name }}</span>
                @if ($showTeam && auth()->user()->currentTeam)
                    <span class="block truncate text-xs text-gray-500 dark:text-dark-300">{{ auth()->user()->currentTeam->name }}</span>
                @endif
            </span>
            <x-icon name="chevron-down" class="size-4 text-gray-400" />
        </button>
    </x-slot:action>

    <x-slot:header>
        <div class="flex items-center gap-2 px-1 py-1">
            <x-avatar :text="auth()->user()->initials()" sm />
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-gray-500 dark:text-dark-300">{{ auth()->user()->email }}</p>
            </div>
        </div>
        <div class="mt-3">
            <x-theme-switch block />
        </div>
    </x-slot:header>

    <x-dropdown.items :text="__('Settings')" icon="cog-6-tooth" :href="route('profile.edit')" navigate />

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <x-dropdown.items :text="__('Log out')" icon="arrow-right-start-on-rectangle" separator type="submit" data-test="logout-button" />
    </form>
</x-dropdown>
