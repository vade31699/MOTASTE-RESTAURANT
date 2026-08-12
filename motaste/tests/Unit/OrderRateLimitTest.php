<?php

use Illuminate\Support\Facades\DB;

/**
 * Rate-limit regression tests for the public order-creation endpoint.
 *
 * These mirror the bootstrapping used by StaffAuthHelpersTest: the app boots
 * with the in-memory testing database, and the shared rate-limit helpers
 * (recordOrderApiRequest / isOrderApiRateLimited) are exercised directly —
 * the exact functions create_order.php calls. No production data is touched.
 */
function bootRateLimitTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database. The app is booted manually
    // (not through Laravel's test harness), so it would otherwise read .env and
    // run against the PRODUCTION database.
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../public/api/_staff_auth_helpers.php';
    require_once __DIR__ . '/../../public/api/_helpers.php';
    require_once __DIR__ . '/../../public/api/_device_auth_helpers.php';

    // The tests touch order_request_log before the first rate-limit call; create
    // it up front so the pre-test cleanup deletes are safe.
    ensureOrderRequestLogTable();
}

/** Set the IP the helpers see, mirroring what a real request would provide. */
function setClientIp(?string $remote, ?string $forwarded = null): void
{
    if ($remote === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $remote;
    }
    if ($forwarded === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
    }
}

test('order requests are rate limited per IP after the 12/minute budget', function () {
    bootRateLimitTestApp();

    $ip = '198.51.100.10';
    setClientIp($ip);
    DB::table('order_request_log')->where('ip_address', $ip)->delete();

    // 12 valid attempts fit within the budget…
    foreach (range(1, 12) as $ignored) {
        recordOrderApiRequest('create_order');
    }
    expect(isOrderApiRateLimited('create_order', 12, 60))->toBeTrue();

    // …and a fresh minute window starts clean.
    DB::table('order_request_log')->where('ip_address', $ip)->delete();
    expect(isOrderApiRateLimited('create_order', 12, 60))->toBeFalse();
});

test('rate limit is per IP — another address is not throttled', function () {
    bootRateLimitTestApp();

    $ipA = '198.51.100.20';
    $ipB = '198.51.100.21';
    DB::table('order_request_log')->whereIn('ip_address', [$ipA, $ipB])->delete();

    setClientIp($ipA);
    foreach (range(1, 12) as $ignored) {
        recordOrderApiRequest('create_order');
    }

    setClientIp($ipB);
    expect(isOrderApiRateLimited('create_order', 12, 60))->toBeFalse();

    DB::table('order_request_log')->whereIn('ip_address', [$ipA, $ipB])->delete();
});

test('spoofed X-Forwarded-For headers cannot bypass the rate limit', function () {
    bootRateLimitTestApp();

    // The client's real address (what the platform's proxy connects from).
    $realIp = '203.0.113.77';
    // The attacker rotates the forwarded header on every request.
    setClientIp($realIp, '1.2.3.4');

    // The resolver must key on REMOTE_ADDR, not the forged header.
    expect(resolveClientIpAddress())->toBe($realIp);
    expect(resolveApiClientIp())->toBe($realIp);

    DB::table('order_request_log')->where('ip_address', $realIp)->delete();

    foreach (range(1, 12) as $i) {
        setClientIp($realIp, '10.0.0.' . $i);
        recordOrderApiRequest('create_order');
    }

    // Still throttled — even though every request claimed a different IP.
    setClientIp($realIp, '10.0.0.99');
    expect(isOrderApiRateLimited('create_order', 12, 60))->toBeTrue();

    DB::table('order_request_log')->where('ip_address', $realIp)->delete();
});

test('IP resolution falls back to forwarded headers only when REMOTE_ADDR is absent', function () {
    bootRateLimitTestApp();

    // Local CLI-style request with no REMOTE_ADDR: the forwarded header is the
    // only source, and it is used rather than returning an empty string.
    setClientIp(null, '198.51.100.99');
    expect(resolveClientIpAddress())->toBe('198.51.100.99');

    // Loopback localhost is not treated as a real client either.
    setClientIp('::1', '198.51.100.100');
    expect(resolveClientIpAddress())->toBe('198.51.100.100');
});
