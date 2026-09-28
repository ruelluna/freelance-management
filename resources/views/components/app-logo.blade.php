<a href="{{ route(auth()->user()->homeRoute()) }}" wire:navigate {{ $attributes->merge(['class' => 'flex items-center gap-2 font-medium']) }}>
    <x-app-logo-icon class="size-8" />
    <span>{{ config('app.name', 'Laravel') }}</span>
</a>
