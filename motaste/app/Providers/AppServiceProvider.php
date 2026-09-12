<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //*
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Schema::defaultStringLength(191);

        // Force HTTPS at the application level (DPA 2.1: no credentials or
        // personal data over plain HTTP). The hosting platform already
        // terminates TLS and redirects, but this makes the redirect explicit
        // even if the app is ever served from a different entrypoint. Local
        // HTTP (php artisan serve) stays usable for development.
        if (! $this->app->environment(['local', 'testing'])) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Strong password policy shared by the registration, password update,
        // and password reset flows. Mirrors public/api/_password_policy.php
        // so both auth systems (Laravel users + pure PHP staff endpoints)
        // enforce the same rules.
        Password::defaults(function () {
            $rule = Password::min(8)
                ->mixedCase()
                ->numbers();

            // The breached-password (uncompromised) check makes an outbound
            // HTTPS call to api.pwnedpasswords.com. Enable it only outside
            // local/testing environments where that call can fail or hang.
            if (! app()->environment(['local', 'testing'])) {
                $rule = $rule->uncompromised();
            }

            return $rule;
        });

        // Throttle password recovery. The controller already enforces a
        // per-account resend window and a 3-attempt cap per code; these add a
        // per-IP ceiling so a single source cannot flood the flow or cycle
        // codes indefinitely. Keyed by IP (not address) so the limit can never
        // be used to probe or lock out a specific account.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('password-reset-verify', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perHour(40)->by($request->ip()),
            ];
        });

        // The write step. Reset tokens are unguessable, but every attempt runs
        // a bcrypt token check plus the password-policy rules, so this guard
        // mainly protects CPU rather than the token space.
        RateLimiter::for('password-reset-store', function (Request $request) {
            return [
                Limit::perMinute(6)->by($request->ip()),
                Limit::perHour(30)->by($request->ip()),
            ];
        });

        // Light ceiling on abandoning a pending reset — the endpoint only
        // clears session state, so this just stops trivial spam.
        RateLimiter::for('password-reset-cancel', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perHour(40)->by($request->ip()),
            ];
        });

        // Per-IP ceiling on login attempts. LoginRequest already locks an
        // account after 5 failures, but its key is email+IP — an attacker can
        // sidestep that by rotating email addresses from a single IP. This
        // caps the total attempt rate per source, whichever account is hit.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perHour(60)->by($request->ip()),
            ];
        });
    }
}
