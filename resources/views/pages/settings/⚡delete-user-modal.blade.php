<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <x-modal id="confirm-user-deletion" :title="__('Are you sure you want to delete your account?')" center size="lg">
        <form id="confirm-user-deletion-form" method="POST" wire:submit="deleteUser" class="space-y-6">
            <p class="text-sm text-gray-500 dark:text-dark-300">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            <x-password wire:model="password" :label="__('Password')" :rules="[]" />
        </form>

        <x-slot:footer>
            <x-button outline :text="__('Cancel')" x-on:click="$tsui.close.modal('confirm-user-deletion')" />

            <x-button color="red" submit :text="__('Delete account')" form="confirm-user-deletion-form" data-test="confirm-delete-user-button" />
        </x-slot:footer>
    </x-modal>

    @if ($errors->isNotEmpty())
        <div x-init="$tsui.open.modal('confirm-user-deletion')"></div>
    @endif
</div>
