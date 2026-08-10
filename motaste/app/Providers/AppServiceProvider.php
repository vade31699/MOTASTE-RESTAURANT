<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

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

        $this->tuneSessionForRequestHost();
    }

    /**
     * Deployment-style .env files often pin the session cookie to the
     * production host (SESSION_DOMAIN) and to HTTPS (SESSION_SECURE_COOKIE).
     * When the app is reached from any other host — localhost during local
     * development — browsers silently drop those cookies, which breaks every
     * session-dependent flow (CSRF-protected writes, staff login, the admin
     * dashboard). Relax the cookie for non-production hosts; the production
     * host keeps the secure settings.
     */
    private function tuneSessionForRequestHost(): void
    {
        try {
            // Only relax cookie security for local development hosts; every
            // other host (including future production aliases) keeps the
            // deployment's secure/domain settings.
            $requestHost = request()->getHost();
            $isLocalHost = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with($requestHost, '.local')
                || str_ends_with($requestHost, '.test');

            if ($isLocalHost) {
                config()->set('session.secure', false);
                config()->set('session.domain', null);
            }

            // Git-for-Windows exports SESSION_PATH as a Windows folder
            // ("C:/Program Files/Git/"), which Laravel's env() helper picks
            // up in place of the .env value. Browsers reject cookies with
            // that path, silently dropping the session. Force the root path.
            $path = (string) config('session.path');
            if ($path !== '/' && ! str_starts_with($path, '/')) {
                config()->set('session.path', '/');
            }
        } catch (\Throwable $e) {
            // Session tuning must never block application boot.
        }
    }
}
