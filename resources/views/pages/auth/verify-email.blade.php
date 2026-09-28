<x-layouts::auth :title="__('Email verification')">
    <div class="mt-4 flex flex-col gap-6">
        <p class="text-center text-sm text-gray-500 dark:text-dark-300">
            {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
        </p>

        @if (session('status') == 'verification-link-sent')
            <p class="text-center font-medium text-sm !dark:text-green-400 !text-green-600">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </p>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-button submit class="w-full" :text="__('Resend verification email')" />
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-button flat submit class="text-sm cursor-pointer" data-test="logout-button" :text="__('Log out')" />
            </form>
        </div>
    </div>
</x-layouts::auth>
