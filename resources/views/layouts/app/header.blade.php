<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    x-data="tallstackui_darkTheme({ default: 'dark' })"
    x-bind:class="{ dark: darkTheme }"
>
    <head>
        @include('partials.head')
    </head>
    <body>
        <x-layout>
            <x-slot:menu>
                <x-side-bar navigate thin-scroll>
                    <x-slot:brand>
                        <div class="px-4 py-5">
                            <x-app-logo />
                        </div>
                    </x-slot:brand>

                    <x-side-bar.separator :text="__('Platform')" />
                    <x-nav.platform-links />
                </x-side-bar>
            </x-slot:menu>

            <x-slot:header>
                <x-layout.header>
                    <x-slot:left>
                        <div class="hidden items-center gap-4 lg:flex">
                            <x-nav.platform-navbar-items />
                        </div>
                    </x-slot:left>
                    <x-slot:right>
                        <x-desktop-user-menu :showTeam="false" />
                        @unless (auth()->user()->isScopedTeamUser(auth()->user()->currentTeam))
                            <div class="max-lg:hidden">
                                <livewire:team-switcher />
                            </div>
                        @endunless
                    </x-slot:right>
                </x-layout.header>
            </x-slot:header>

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
