<?php

namespace App\Providers;

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
    }
}
