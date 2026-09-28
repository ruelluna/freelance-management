<?php

namespace App\Providers;

use App\Auth\SessionGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
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
        $this->configureImpersonationGuard();
        $this->ensureEnvBarScript();

        Gate::define('envbar::view', function (?User $user, ?User $viewer = null): bool {
            $viewer ??= $user;

            if (! $viewer instanceof User) {
                return false;
            }

            return in_array($viewer->email, config('envbar.for_authenticated_users.viewers', []), true);
        });
    }

    protected function configureImpersonationGuard(): void
    {
        $auth = $this->app->make(AuthManager::class);

        $auth->extend('session', function (Application $app, string $name, array $config) use ($auth): SessionGuard {
            $provider = $auth->createUserProvider($config['provider']);

            $guard = new SessionGuard($name, $provider, $app['session.store']);

            if (method_exists($guard, 'setCookieJar')) {
                $guard->setCookieJar($app['cookie']);
            }

            if (method_exists($guard, 'setDispatcher')) {
                $guard->setDispatcher($app['events']);
            }

            if (method_exists($guard, 'setRequest')) {
                $guard->setRequest($app->refresh('request', $guard, 'setRequest'));
            }

            return $guard;
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
