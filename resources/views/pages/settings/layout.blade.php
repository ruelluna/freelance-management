<div class="flex items-start max-md:flex-col">
    <nav class="me-10 w-full pb-4 md:w-[220px]" aria-label="{{ __('Settings') }}">
        <div class="flex flex-col gap-1">
            <x-link :href="route('profile.edit')" :text="__('Profile')" navigate :color="request()->routeIs('profile.edit') ? 'primary' : 'gray'" />
            <x-link :href="route('security.edit')" :text="__('Security')" navigate :color="request()->routeIs('security.edit') ? 'primary' : 'gray'" />
            <x-link :href="route('teams.index')" :text="__('Teams')" navigate :color="request()->routeIs('teams.*') ? 'primary' : 'gray'" />
            <x-link :href="route('appearance.edit')" :text="__('Appearance')" navigate :color="request()->routeIs('appearance.edit') ? 'primary' : 'gray'" />
            @if (auth()->user()->currentTeam && auth()->user()->canManageTodoist(auth()->user()->currentTeam))
                <x-link :href="route('todoist.edit')" :text="__('Todoist')" navigate :color="request()->routeIs('todoist.edit') ? 'primary' : 'gray'" />
            @endif
        </div>
    </nav>

    <hr class="w-full border-gray-200 md:hidden dark:border-dark-700" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $heading ?? '' }}</h2>
        <p class="text-sm text-gray-500 dark:text-dark-300">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
