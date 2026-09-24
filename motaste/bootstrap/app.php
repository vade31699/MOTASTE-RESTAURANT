<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Apply conservative security headers to every routed response.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Upgrade any plain-HTTP request to HTTPS (DPA 2.1). Registered after
        // SecurityHeaders so the redirect response carries the same headers.
        $middleware->append(\App\Http\Middleware\ForceHttps::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* routes always render JSON; any other request that explicitly
        // asks for JSON (Accept: application/json) does too — this lets the
        // login-page modal consume validation errors for /forgot-password and
        // /reset-password as 422 JSON instead of a redirect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
