<?php

use Illuminate\Support\Facades\DB;

/**
 * Boot the Laravel application manually. The Feature test harness in this repo
 * currently fails on RefreshDatabase (Mockery OutputStyle issue), so these
 * helper tests bootstrap the app directly and create their own schema.
 */
function bootTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database. The app is booted manually
    // (not through Laravel's test harness), so without this the tests would
    // read .env and run against the PRODUCTION database.
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

    // The tests touch login_attempts / staff_session_tokens before any helper
    // creates them; ensure the schema up front so pre-test cleanup deletes work.
    ensureStaffEnhancementSchema();
}

// Boot before the first test runs, not inside it: the app bootstrap registers
// global error/exception handlers, and PHPUnit marks whichever test triggers
// the boot as "risky" (did not remove its own error handlers) if it happens
// mid-test. beforeAll runs outside per-test handler accounting.
beforeAll(function () {
    bootTestApp();
});

test('failed login attempts trigger the brute-force rate limiter', function () {
    bootTestApp();

    $email = 'ratelimit-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    // One attempt below the lockout threshold is still allowed.
    foreach (range(1, STAFF_LOGIN_MAX_ATTEMPTS - 1) as $ignored) {
        recordLoginAttempt($email, false);
    }
    expect(isLoginRateLimited($email))->toBeFalse();

    // Hitting the threshold locks the account.
    recordLoginAttempt($email, false);
    expect(isLoginRateLimited($email))->toBeTrue();

    DB::table('login_attempts')->where('email', $email)->delete();
    expect(isLoginRateLimited($email))->toBeFalse();
});

test('a successful login clears the failed-attempt counter', function () {
    bootTestApp();

    $email = 'success-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    recordLoginAttempt($email, false);
    recordLoginAttempt($email, true);

    expect(isLoginRateLimited($email))->toBeFalse();
});

test('staff session tokens can be issued, resolved, and revoked', function () {
    bootTestApp();

    $email = 'token-test@example.com';
    DB::table('staff_session_tokens')->where('email', $email)->delete();

    $token = issueStaffSessionToken($email, 'Admin');

    expect(strlen($token))->toBe(64);

    $identity = resolveStaffSessionToken($token);
    expect($identity)->not->toBeNull();
    expect($identity['email'])->toBe($email);
    expect($identity['role'])->toBe('Admin');

    revokeStaffSessionToken($token);
    expect(resolveStaffSessionToken($token))->toBeNull();
});

test('revoking all tokens ends every session for the account', function () {
    bootTestApp();

    $email = 'revoke-all-test@example.com';
    DB::table('staff_session_tokens')->where('email', $email)->delete();

    $tokenA = issueStaffSessionToken($email, 'Cashier');
    $tokenB = issueStaffSessionToken($email, 'Cashier');

    revokeAllStaffSessionTokens($email);

    expect(resolveStaffSessionToken($tokenA))->toBeNull();
    expect(resolveStaffSessionToken($tokenB))->toBeNull();
});

test('expired session tokens are rejected', function () {
    bootTestApp();

    $email = 'expired-test@example.com';
    DB::table('staff_session_tokens')->where('email', $email)->delete();

    $token = issueStaffSessionToken($email, 'Admin');
    DB::table('staff_session_tokens')
        ->where('email', $email)
        ->update(['expires_at' => now()->subMinute()->toDateTimeString()]);

    expect(resolveStaffSessionToken($token))->toBeNull();
});
