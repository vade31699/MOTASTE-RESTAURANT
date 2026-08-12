<?php

use Illuminate\Support\Facades\DB;

/**
 * Regression tests for the stateless CSRF token implementation.
 *
 * The CSRF token used to live in PHP's native $_SESSION, which does not
 * survive across serverless containers (each Laravel Cloud instance has its
 * own filesystem). A token issued by container A was rejected by container B,
 * causing intermittent "Invalid CSRF token" errors. Tokens are now
 * HMAC-signed, self-contained values bound to the session-ID cookie — any
 * container can validate them without shared storage.
 */
function bootCsrfTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database (mirrors the other unit
    // tests); never touch the production database.
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

    require_once __DIR__ . '/../../public/api/csrf_guard.php';
}

test('issued CSRF tokens validate successfully', function () {
    bootCsrfTestApp();

    $token = getOrCreateCsrfToken();

    expect($token)->not->toBe('');
    expect(isValidCsrfToken($token))->toBeTrue();
});

test('tampered or malformed tokens are rejected', function () {
    bootCsrfTestApp();

    $token = getOrCreateCsrfToken();

    // Flip a character inside the signed payload.
    $tampered = substr_replace($token, $token[5] === 'A' ? 'B' : 'A', 5, 1);
    expect(isValidCsrfToken($tampered))->toBeFalse();

    // Random garbage.
    expect(isValidCsrfToken('not-a-token'))->toBeFalse();
    expect(isValidCsrfToken(''))->toBeFalse();
});

test('a token issued for a different session ID is rejected', function () {
    bootCsrfTestApp();

    // Simulate a browser whose session cookie is absent on the next request
    // (container rotation on serverless): start a fresh session, which yields
    // a different session ID than the one the token was bound to.
    $firstSession = session_id();
    $token = getOrCreateCsrfToken();

    session_write_close();
    session_id($firstSession . '-rotated');
    session_start();

    expect(isValidCsrfToken($token))->toBeFalse();

    // And a token issued for the NEW session validates again.
    $fresh = getOrCreateCsrfToken();
    expect(isValidCsrfToken($fresh))->toBeTrue();
});

test('tokens issued by get_csrf_token endpoint round-trip through validation', function () {
    bootCsrfTestApp();

    // Mirrors exactly what public/api/get_csrf_token.php does.
    $token = getOrCreateCsrfToken();
    $payload = json_encode(['success' => true, 'csrfToken' => $token]);

    expect(json_decode($payload, true)['csrfToken'])->toBe($token);
    expect(isValidCsrfToken(json_decode($payload, true)['csrfToken']))->toBeTrue();

    // Cleanup guard: the DB facade must be resolvable (bootstrap already ran).
    expect(DB::connection()->getDatabaseName())->not->toBe('');
});
