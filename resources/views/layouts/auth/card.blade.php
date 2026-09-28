<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    x-data="tallstackui_darkTheme({ default: 'dark' })"
    x-bind:class="{ dark: darkTheme }"
>
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-800 antialiased dark:bg-dark-900 dark:text-dark-100">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <x-app-logo-icon class="size-14" />
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <x-card>
                    <div class="px-6 py-4">{{ $slot }}</div>
                </x-card>
            </div>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
