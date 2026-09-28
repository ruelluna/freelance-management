<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')" />
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <x-input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <x-password
                    name="password"
                    :label="__('Password')"
                    :rules="[]"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                />

                @if (Route::has('password.request'))
                    <x-link class="absolute top-0 text-sm end-0" :href="route('password.request')" :text="__('Forgot your password?')" navigate />
                @endif
            </div>

            <!-- Remember Me -->
            <x-checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <x-button submit class="w-full" data-test="login-button" :text="__('Log in')" />
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-dark-300">
                <span>{{ __('Don\'t have an account?') }}</span>
                <x-link
                    :href="$teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register')"
                    data-test="register-link"
                    :text="__('Sign up')"
                    navigate
                />
            </div>
        @endif
    </div>
</x-layouts::auth>
