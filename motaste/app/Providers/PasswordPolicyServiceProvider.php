<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class PasswordPolicyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * Enforces the strong password policy:
     * - Minimum 8 characters
     * - Require letters, numbers, and symbols (complexity)
     * - Check against compromised/breached password databases (HaveIBeenPwned)
     * - Password confirmation is enforced per-validation rule in controllers
     */
    public function boot(): void
    {
        // Override Laravel's default password rule to meet the strong policy.
        // Password::defaults() is used in RegisteredUserController,
        // NewPasswordController, PasswordController, and ConfirmablePasswordController.
        Password::defaults(function () {
            return Password::min(8)
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });
    }
}
