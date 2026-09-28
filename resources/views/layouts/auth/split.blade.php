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
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col bg-gray-900 p-10 text-white lg:flex">
                <a href="{{ route('home') }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <x-app-logo-icon on-dark class="me-2 size-10" />
                    {{ config('app.name', 'Laravel') }}
                </a>

                @php
                    [$message, $author] = str(Illuminate\Foundation\Inspiring::quotes()->random())->explode('-');
                @endphp

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <p class="text-lg font-medium">&ldquo;{{ trim($message) }}&rdquo;</p>
                        <footer class="text-sm text-white/80">{{ trim($author) }}</footer>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <x-app-logo-icon class="size-14" />
                        <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
