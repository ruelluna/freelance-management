@props(['onDark' => false])

@if ($onDark)
    <img
        src="{{ asset('zt-logo-white.png') }}"
        alt=""
        {{ $attributes->class(['object-contain']) }}
    >
@else
    <span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center']) }}>
        <img src="{{ asset('zt-logo.png') }}" alt="" class="size-full object-contain dark:hidden">
        <img src="{{ asset('zt-logo-white.png') }}" alt="" class="hidden size-full object-contain dark:block">
    </span>
@endif
