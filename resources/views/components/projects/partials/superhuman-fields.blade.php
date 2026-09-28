@php
    $matchEmails = array_values(array_unique(array_filter([
        strtolower((string) auth()->user()?->email),
        strtolower((string) ($accountEmail ?? '')),
    ])));
@endphp

<x-select.native wire:model.live="docId" wire:change="docChanged" :label="__('Doc')" data-test="superhuman-doc">
    <option value="">{{ __('Select a doc') }}</option>
    @foreach ($docs as $doc)
        <option value="{{ $doc['id'] }}" wire:key="superhuman-doc-{{ $doc['id'] }}">{{ $doc['name'] }}</option>
    @endforeach
</x-select.native>

<x-select.native wire:model.live="pageId" wire:change="pageChanged" :label="__('Page')" data-test="superhuman-page">
    <option value="">{{ __('Select a page') }}</option>
    @foreach ($pages as $page)
        <option value="{{ $page['id'] }}" wire:key="superhuman-page-{{ $page['id'] }}">{{ $page['name'] }}</option>
    @endforeach
</x-select.native>

<x-select.native wire:model="boardId" :label="__('Table')" data-test="superhuman-board">
    <option value="">{{ __('Select a table') }}</option>
    @foreach ($boards as $board)
        <option value="{{ $board['id'] }}" wire:key="superhuman-board-{{ $board['id'] }}">{{ $board['name'] }}</option>
    @endforeach
</x-select.native>

<p class="text-sm text-gray-500 dark:text-dark-300" data-test="superhuman-match-emails">
    @if ($matchEmails === [])
        {{ __('Rows in that table are imported when Assigned Into lists your email and the status is not Done.') }}
    @else
        {{ __('Rows in that table are imported when Assigned Into lists :emails and the status is not Done.', ['emails' => implode(' or ', $matchEmails)]) }}
    @endif
</p>
