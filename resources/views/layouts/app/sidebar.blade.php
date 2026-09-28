@props(['title' => null])

<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    x-data="tallstackui_darkTheme({ default: 'dark' })"
    x-bind:class="{ dark: darkTheme }"
>
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body>
        <x-layout>
            <x-slot:header>
                <x-layout.header>
                    <x-slot:left>
                        @if (filled($title))
                            <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</span>
                        @endif
                    </x-slot:left>

                    <x-slot:right>
                        <x-desktop-user-menu :showTeam="false" />
                    </x-slot:right>
                </x-layout.header>
            </x-slot:header>

            <x-slot:menu>
                <x-side-bar collapsible smart navigate thin-scroll>
                    <x-slot:brand>
                        <div class="flex flex-col gap-3 px-3 py-4">
                            <a href="{{ route(auth()->user()->homeRoute()) }}" wire:navigate class="flex items-center" aria-label="{{ config('app.name') }}">
                                <x-app-logo-icon class="size-8" />
                            </a>

                            @unless (auth()->user()->isScopedTeamUser(auth()->user()->currentTeam))
                                <livewire:team-switcher />
                            @endunless
                        </div>
                    </x-slot:brand>

                    <x-slot:brand-collapsed>
                        <div class="flex justify-center py-4">
                            <a href="{{ route(auth()->user()->homeRoute()) }}" wire:navigate aria-label="{{ config('app.name') }}">
                                <x-app-logo-icon class="size-8" />
                            </a>
                        </div>
                    </x-slot:brand-collapsed>

                    <x-side-bar.separator :text="__('Platform')" line />
                    <x-nav.platform-links />
                </x-side-bar>
            </x-slot:menu>

            {{ $slot }}
        </x-layout>

        @can('create', App\Models\Team::class)
            <livewire:create-team-modal />
        @endcan

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
