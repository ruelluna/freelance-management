@props([
    'status',
])

@if ($status)
    <x-alert color="green" light icon="check-circle" :text="$status" {{ $attributes }} />
@endif
