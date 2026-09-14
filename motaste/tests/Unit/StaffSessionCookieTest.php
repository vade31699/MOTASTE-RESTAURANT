<?php

/**
 * Regression tests for the PHP session cookie lifetime.
 *
 * The staff session cookie must last STAFF_SESSION_LIFETIME_SECONDS so "stay
 * logged in" survives a browser restart. Two helpers can start the session and
 * the FIRST one to call session_start() fixes the cookie the browser stores, so
 * what matters is the order they run in:
 *
 *   verify_device_login.php validates CSRF (csrf_guard.php) BEFORE it calls
 *   ensureStaffAuthSession().
 *
 * csrf_guard.php used to hardcode `lifetime => 0` for that first start and
 * ensureStaffAuthSession() bailed out early because a session was already
 * active, so the browser got a browser-session cookie: closing the browser
 * logged the user out no matter what "remember me" said.
 */

function bootStaffSessionCookieTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database (mirrors the other unit
    // tests). The app is booted manually, so without this the tests would read
    // .env and run against the PRODUCTION database.
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
    require_once __DIR__ . '/../../public/api/_staff_auth_helpers.php';
}

// Boot before the first test runs, not inside it: the app bootstrap registers
// global error/exception handlers, and PHPUnit marks whichever test triggers
// the boot as "risky" (did not remove its own error handlers). beforeAll runs
// outside per-test handler accounting.
beforeAll(function () {
    bootStaffSessionCookieTestApp();
});

/**
 * Leave the process with no active session and the pre-fix cookie ini value
 * (lifetime 0), so every test starts from the same state no matter what an
 * earlier test in the same process did.
 */
function resetStaffSessionCookieTestState(int $lifetime = 0): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_write_close();
    }

    session_set_cookie_params(['lifetime' => $lifetime, 'path' => '/']);
}

test('a CSRF-first request keeps the persistent staff session cookie lifetime', function () {
    bootStaffSessionCookieTestApp();
    resetStaffSessionCookieTestState(0);

    // Exactly the order verify_device_login.php uses: CSRF validation first,
    // which is what starts the session.
    $token = getOrCreateCsrfToken();
    expect(isValidCsrfToken($token))->toBeTrue();

    $params = session_get_cookie_params();

    // Used to be 0 — the CSRF guard hardcoded a browser-session cookie, so the
    // staff session died with the browser.
    expect($params['lifetime'])->toBe(staffSessionLifetimeSeconds());
    expect($params['path'])->toBe('/');
    expect($params['httponly'])->toBeTrue();
    expect($params['samesite'])->toBe('Lax');

    // The staff helper runs second and must agree rather than fight it.
    ensureStaffAuthSession();
    expect(session_get_cookie_params()['lifetime'])->toBe(staffSessionLifetimeSeconds());
});

test('the staff helper applies the persistent cookie when it starts the session', function () {
    bootStaffSessionCookieTestApp();
    resetStaffSessionCookieTestState(0);

    // No CSRF call first: the staff helper is the one that starts the session.
    ensureStaffAuthSession();

    $params = session_get_cookie_params();
    expect($params['lifetime'])->toBe(staffSessionLifetimeSeconds());
    expect($params['path'])->toBe('/');
    expect($params['httponly'])->toBeTrue();
    expect($params['samesite'])->toBe('Lax');
});

test('the staff helper re-issues the cookie on an already active session', function () {
    bootStaffSessionCookieTestApp();
    resetStaffSessionCookieTestState(0);

    // A public endpoint with no staff helper in scope can start the session
    // first (e.g. save_review.php), leaving an already signed-in staff member
    // with a browser-session cookie. That is the downgrade case.
    session_start();
    $_SESSION['staff'] = ['role' => 'Admin', 'email' => 'admin@example.com'];
    $sessionIdBeforeRepair = session_id();
    expect(session_get_cookie_params()['lifetime'])->toBe(0);

    ensureStaffAuthSession();

    // The repair must reuse the SAME session id: regenerating it would
    // invalidate the signed CSRF tokens, which are bound to the id. It must
    // also leave the authenticated session intact — and it must not throw.
    // (setcookie() rejects the 'lifetime' key that session_set_cookie_params()
    // takes; passing it straight through raised a ValueError here, which would
    // have 500'd every request where CSRF started the session first.)
    expect(session_id())->toBe($sessionIdBeforeRepair);
    expect($_SESSION['staff']['role'])->toBe('Admin');

    // NOTE: the Set-Cookie header itself cannot be asserted here — the CLI SAPI
    // does not collect response headers, so headers_list() is always empty.
    // The re-issued cookie is covered by the other tests through the cookie
    // params, and by an HTTP-level test if one is added later.
});
