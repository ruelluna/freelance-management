<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Delete account') }}</h2>
        <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Delete your account and all of its resources') }}</p>
    </div>

    <x-button color="red" :text="__('Delete account')" data-test="delete-user-button" x-on:click="$tsui.open.modal('confirm-user-deletion')" />

    <livewire:pages::settings.delete-user-modal />
</section>
