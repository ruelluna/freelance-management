<?php

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showVerificationStep = false;

    public bool $setupComplete = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(bool $requiresConfirmation): void
    {
        $this->requiresConfirmation = $requiresConfirmation;
    }

    #[On('start-two-factor-setup')]
    public function startTwoFactorSetup(): void
    {
        $enableTwoFactorAuthentication = app(EnableTwoFactorAuthentication::class);
        $enableTwoFactorAuthentication(auth()->user());

        $this->loadSetupData();
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user()?->fresh();

        try {
            if (! $user || ! $user->two_factor_secret) {
                throw new Exception('Two-factor setup secret is not available.');
            }

            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showVerificationStep',
            'setupComplete',
        );

        $this->resetErrorBag();
    }

    /**
     * Get the current modal configuration state.
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->setupComplete) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Verify authentication code'),
                'description' => __('Enter the 6-digit code from your authenticator app.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.'),
            'buttonText' => __('Continue'),
        ];
    }
}; ?>

<x-modal
    id="two-factor-setup-modal"
    :title="$this->modalConfig['title']"
    center
    size="lg"
    x-on:close="$wire.closeModal()"
>
    <div class="space-y-6">
        <div class="flex flex-col items-center space-y-4">
            <div class="w-auto rounded-full border border-gray-200 bg-white p-0.5 shadow-sm dark:border-dark-600 dark:bg-dark-800">
                <div class="relative overflow-hidden rounded-full border border-gray-200 bg-gray-100 p-2.5 dark:border-dark-600 dark:bg-dark-700">
                    <div class="absolute inset-0 flex h-full w-full items-stretch justify-around divide-x divide-gray-200 opacity-50 dark:divide-dark-500 [&>div]:flex-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <div></div>
                        @endfor
                    </div>

                    <div class="absolute inset-0 flex h-full w-full flex-col items-stretch justify-around divide-y divide-gray-200 opacity-50 dark:divide-dark-500 [&>div]:flex-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <div></div>
                        @endfor
                    </div>

                    <x-icon name="qr-code" class="relative z-20 size-6 dark:text-white" />
                </div>
            </div>

            <p class="text-center text-sm text-gray-500 dark:text-dark-300">{{ $this->modalConfig['description'] }}</p>
        </div>

        @if ($showVerificationStep)
            <div class="space-y-6">
                <div
                    class="flex flex-col items-center justify-center space-y-3"
                    x-data
                    x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                >
                    <x-pin
                        wire:model="code"
                        label="OTP Code"
                        :length="6"
                        numbers
                        class="mx-auto"
                    />
                </div>

                <div class="flex items-center space-x-3">
                    <x-button
                        outline
                        class="flex-1"
                        :text="__('Back')"
                        wire:click="resetVerification"
                    />

                    <x-button
                        class="flex-1"
                        :text="__('Confirm')"
                        wire:click="confirmTwoFactor"
                        x-bind:disabled="$wire.code.length < 6"
                    />
                </div>
            </div>
        @else
            @error('setupData')
                <x-alert color="red" light icon="x-circle" :text="$message" />
            @enderror

            <div class="flex justify-center">
                <div class="relative aspect-square w-64 overflow-hidden rounded-lg border border-gray-200 dark:border-dark-700">
                    @empty($qrCodeSvg)
                        <div class="absolute inset-0 flex animate-pulse items-center justify-center bg-white dark:bg-dark-700">
                            <x-spinner />
                        </div>
                    @else
                        <div class="flex h-full items-center justify-center p-4">
                            <div class="rounded bg-white p-3 dark:brightness-150 dark:invert">
                                {!! $qrCodeSvg !!}
                            </div>
                        </div>
                    @endempty
                </div>
            </div>

            <div>
                <x-button
                    block
                    :text="$this->modalConfig['buttonText']"
                    :disabled="$errors->has('setupData')"
                    wire:click="showVerificationIfNecessary"
                />
            </div>

            <div class="space-y-4">
                <div class="relative flex w-full items-center justify-center">
                    <div class="absolute inset-0 top-1/2 h-px w-full bg-gray-200 dark:bg-dark-600"></div>
                    <span class="relative bg-white px-2 text-sm text-gray-600 dark:bg-dark-800 dark:text-dark-300">
                        {{ __('or, enter the code manually') }}
                    </span>
                </div>

                <div
                    class="flex items-center space-x-2"
                    x-data="{
                        copied: false,
                        async copy() {
                            try {
                                await navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1500);
                            } catch (e) {
                                console.warn('Could not copy to clipboard');
                            }
                        }
                    }"
                >
                    <div class="flex w-full items-stretch rounded-xl border border-gray-200 dark:border-dark-700">
                        @empty($manualSetupKey)
                            <div class="flex w-full items-center justify-center bg-gray-100 p-3 dark:bg-dark-700">
                                <x-spinner xs />
                            </div>
                        @else
                            <input
                                type="text"
                                readonly
                                value="{{ $manualSetupKey }}"
                                class="w-full bg-transparent p-3 text-gray-900 outline-none dark:text-dark-100"
                            />

                            <button
                                type="button"
                                x-on:click="copy()"
                                class="cursor-pointer border-l border-gray-200 px-3 transition-colors dark:border-dark-600"
                            >
                                <x-icon name="document-duplicate" x-show="!copied" class="size-5" />
                                <x-icon name="check" x-show="copied" class="size-5 text-green-500" />
                            </button>
                        @endempty
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-modal>
