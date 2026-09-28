<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="space-y-6 rounded-xl border border-zinc-200 py-6 shadow-sm dark:border-white/10"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="space-y-2 px-6">
        <div class="flex items-center gap-2">
            <x-icon name="lock-closed" class="size-4" />
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('2FA recovery codes') }}</h3>
        </div>
        <p class="text-sm text-gray-500 dark:text-dark-300">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </p>
    </div>

    <div class="px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-button
                x-show="!showRecoveryCodes"
                icon="eye"
                :text="__('View recovery codes')"
                x-on:click="showRecoveryCodes = true"
                aria-expanded="false"
                aria-controls="recovery-codes-section"
            />

            <x-button
                x-show="showRecoveryCodes"
                icon="eye-slash"
                :text="__('Hide recovery codes')"
                x-on:click="showRecoveryCodes = false"
                aria-expanded="true"
                aria-controls="recovery-codes-section"
            />

            @if (filled($recoveryCodes))
                <x-button
                    x-show="showRecoveryCodes"
                    icon="arrow-path"
                    outline
                    :text="__('Regenerate codes')"
                    wire:click="regenerateRecoveryCodes"
                />
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="relative overflow-hidden"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="mt-3 space-y-3">
                @error('recoveryCodes')
                    <x-alert color="red" light icon="x-circle" :text="$message" />
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="grid gap-1 rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-white/5"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text"
                                wire:loading.class="animate-pulse opacity-50"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-500 dark:text-dark-300">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
