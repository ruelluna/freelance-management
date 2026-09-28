<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->ensureEnvBarScript();

        Gate::define('envbar::view', function (?User $user, ?User $viewer = null): bool {
            $viewer ??= $user;

            if (! $viewer instanceof User) {
                return false;
            }

            return in_array($viewer->email, config('envbar.for_authenticated_users.viewers', []), true);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

    }

    /**
     * EnvBar 2.0 publishes its script as app2.js but loads app.js.
     */
    protected function ensureEnvBarScript(): void
    {
        $directory = base_path('vendor/tallstackui/envbar/public/build');
        $script = $directory.'/app.js';
        $published = $directory.'/app2.js';

        if (is_file($script) || ! is_file($published)) {
            return;
        }

        copy($published, $script);
    }
}
