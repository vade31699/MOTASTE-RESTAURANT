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

        $middleware->alias([
            'staff.session' => \App\Http\Middleware\EnsureStaffSession::class,
        ]);

        // Legacy front-end parity: these write/read endpoints were historically
        // callable without a CSRF token. They remain protected by the staff
        // session middleware where applicable; the consolidated clean routes
        // under /api/staff/* are all CSRF-protected normally.
        $middleware->validateCsrfTokens(except: [
            // Legacy login/verification flows never sent a CSRF token.
            'api/authenticate_staff.php',
            'api/verify_device_login.php',
            'api/create_order.php',
            'api/get_order_status.php',
            'api/update_inventory.php',
            'api/delete_inventory_item.php',
            'api/save_custom_menu.php',
            'api/mark_order_complete.php',
            'api/update_pending_order_item.php',
            'api/add_activity_log.php',
            'api/get_pending_orders.php',
            'api/get_completed_orders.php',
            'api/create_staff.php',
            'api/update_staff.php',
            'api/delete_staff.php',
            'api/upload_special_food_image.php',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
