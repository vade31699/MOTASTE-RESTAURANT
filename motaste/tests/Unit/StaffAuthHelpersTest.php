<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    foreach (range(1, staffLoginMaxAttempts() - 1) as $ignored) {
        recordLoginAttempt($email, false);
    }
    expect(isLoginRateLimited($email))->toBeFalse();

    // Hitting the threshold locks the account.
    recordLoginAttempt($email, false);
    expect(isLoginRateLimited($email))->toBeTrue();

    DB::table('login_attempts')->where('email', $email)->delete();
    expect(isLoginRateLimited($email))->toBeFalse();
});

/**
 * Set (or clear with null) an env var across the places Laravel's env()
 * repository reads from, so an override is visible mid-test.
 */
function setStaffLoginTestEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

test('staff security settings are overridable from the environment', function () {
    bootTestApp();

    $knownKeys = [
        'STAFF_LOGIN_MAX_ATTEMPTS',
        'STAFF_LOGIN_LOCKOUT_MINUTES',
        'STAFF_LOGIN_IP_MAX_ATTEMPTS',
        'STAFF_LOGIN_IP_LOCKOUT_MINUTES',
        'STAFF_LOGIN_CAPTCHA_THRESHOLD',
        'STAFF_SESSION_LIFETIME_SECONDS',
        'STAFF_SESSION_TOKEN_TTL_DAYS',
    ];

    // Remember and restore any pre-existing values so this test cannot leak
    // configuration into the rest of the suite.
    $original = [];
    foreach ($knownKeys as $key) {
        $value = getenv($key);
        $original[$key] = $value === false ? null : $value;
        setStaffLoginTestEnv($key, null);
    }

    try {
        // Blank/absent → the compiled-in defaults.
        expect(staffLoginMaxAttempts())->toBe(STAFF_LOGIN_MAX_ATTEMPTS_DEFAULT);
        expect(staffLoginLockoutMinutes())->toBe(STAFF_LOGIN_LOCKOUT_MINUTES_DEFAULT);
        expect(staffLoginIpMaxAttempts())->toBe(STAFF_LOGIN_IP_MAX_ATTEMPTS_DEFAULT);
        expect(staffLoginIpLockoutMinutes())->toBe(STAFF_LOGIN_IP_LOCKOUT_MINUTES_DEFAULT);
        expect(staffLoginCaptchaThreshold())->toBe(STAFF_LOGIN_CAPTCHA_THRESHOLD_DEFAULT);
        expect(staffSessionLifetimeSeconds())->toBe(STAFF_SESSION_LIFETIME_SECONDS_DEFAULT);
        expect(staffSessionTokenTtlDays())->toBe(STAFF_SESSION_TOKEN_TTL_DAYS_DEFAULT);

        // Explicit values are honored.
        setStaffLoginTestEnv('STAFF_LOGIN_MAX_ATTEMPTS', '7');
        setStaffLoginTestEnv('STAFF_LOGIN_LOCKOUT_MINUTES', '10');
        setStaffLoginTestEnv('STAFF_LOGIN_IP_MAX_ATTEMPTS', '99');
        setStaffLoginTestEnv('STAFF_LOGIN_IP_LOCKOUT_MINUTES', '15');
        setStaffLoginTestEnv('STAFF_LOGIN_CAPTCHA_THRESHOLD', '1');
        setStaffLoginTestEnv('STAFF_SESSION_LIFETIME_SECONDS', '600');
        setStaffLoginTestEnv('STAFF_SESSION_TOKEN_TTL_DAYS', '2');

        expect(staffLoginMaxAttempts())->toBe(7);
        expect(staffLoginLockoutMinutes())->toBe(10);
        expect(staffLoginIpMaxAttempts())->toBe(99);
        expect(staffLoginIpLockoutMinutes())->toBe(15);
        expect(staffLoginCaptchaThreshold())->toBe(1);
        expect(staffSessionLifetimeSeconds())->toBe(600);
        expect(staffSessionTokenTtlDays())->toBe(2);

        // Garbage falls back to the default; 0/negative clamps to 1 so a
        // misconfiguration can never disable a limit.
        setStaffLoginTestEnv('STAFF_LOGIN_MAX_ATTEMPTS', 'not-a-number');
        expect(staffLoginMaxAttempts())->toBe(STAFF_LOGIN_MAX_ATTEMPTS_DEFAULT);

        setStaffLoginTestEnv('STAFF_LOGIN_MAX_ATTEMPTS', '0');
        expect(staffLoginMaxAttempts())->toBe(1);

        setStaffLoginTestEnv('STAFF_LOGIN_IP_MAX_ATTEMPTS', '-5');
        expect(staffLoginIpMaxAttempts())->toBe(1);
    } finally {
        foreach ($original as $key => $value) {
            setStaffLoginTestEnv($key, $value);
        }
    }
});

test('a successful login clears the failed-attempt counter', function () {
    bootTestApp();

    $email = 'success-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    recordLoginAttempt($email, false);
    recordLoginAttempt($email, true);

    expect(isLoginRateLimited($email))->toBeFalse();
});

test('an issued session token honors the STAFF_SESSION_TOKEN_TTL_DAYS override', function () {
    bootTestApp();

    $original = getenv('STAFF_SESSION_TOKEN_TTL_DAYS');
    setStaffLoginTestEnv('STAFF_SESSION_TOKEN_TTL_DAYS', '2');

    try {
        $email = 'token-ttl-test@example.com';
        DB::table('staff_session_tokens')->where('email', $email)->delete();

        issueStaffSessionToken($email, 'Admin');

        $expiresAt = \Illuminate\Support\Carbon::parse(
            DB::table('staff_session_tokens')->where('email', $email)->value('expires_at')
        );

        // The token must expire ~2 days out, not the 30-day default.
        expect(abs($expiresAt->diffInSeconds(now()->addDays(2))))->toBeLessThan(60);

        DB::table('staff_session_tokens')->where('email', $email)->delete();
    } finally {
        setStaffLoginTestEnv('STAFF_SESSION_TOKEN_TTL_DAYS', $original === false ? null : $original);
    }
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

test('rotating a staff session keeps a valid replacement token available', function () {
    bootTestApp();

    $email = 'rotate-token-test@example.com';
    DB::table('staff_session_tokens')->where('email', $email)->delete();

    $oldToken = issueStaffSessionToken($email, 'Cashier');
    $replacement = rotateStaffSessionToken($email, 'Cashier', $oldToken);

    expect($replacement)->not->toBe($oldToken);
    expect(resolveStaffSessionToken($replacement)['email'])->toBe($email);
    expect(resolveStaffSessionToken($oldToken))->toBeNull();

    DB::table('staff_session_tokens')->where('email', $email)->delete();
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

/**
 * Insert a login_attempts row directly so tests control the IP, timestamp,
 * and success flag independently of resolveClientIpAddress().
 */
function insertLoginAttemptRow(string $email, string $ip, bool $success, ?string $attemptedAt = null): void
{
    DB::table('login_attempts')->insert([
        'email' => strtolower(trim($email)),
        'ip_address' => $ip,
        'success' => $success,
        'attempted_at' => $attemptedAt ?? now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

test('suspicious login: success from a new IP for an established account is flagged', function () {
    bootTestApp();

    $email = 'newip-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    // Established account: successful logins known, all from a home IP.
    insertLoginAttemptRow($email, '203.0.113.10', true, now()->subDays(10)->toDateTimeString());

    // A login attempt from a brand-new IP is suspicious.
    expect(isSuspiciousLoginAttempt($email, '198.51.100.77'))->toBeTrue();

    // The usual IP is not suspicious (no new-IP signal, no failures anywhere).
    expect(isSuspiciousLoginAttempt($email, '203.0.113.10'))->toBeFalse();

    DB::table('login_attempts')->where('email', $email)->delete();
});

test('suspicious login: an account with no success history is not new-IP flagged', function () {
    bootTestApp();

    $email = 'firstlogin-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    // Brand-new account with zero history: Signal 1 requires prior successes
    // to establish a "normal" IP, so nothing is flagged on the first login.
    expect(isSuspiciousLoginAttempt($email, '198.51.100.77'))->toBeFalse();

    // Same-IP failures only: Signal 2 excludes the current IP and Signal 3
    // needs 3+ distinct emails, so a lone retry from the same IP stays clean.
    insertLoginAttemptRow($email, '203.0.113.10', false, now()->subDays(2)->toDateTimeString());
    expect(isSuspiciousLoginAttempt($email, '203.0.113.10'))->toBeFalse();

    // ...but probing that same account from a DIFFERENT IP trips Signal 2
    // (someone else failed against it recently).
    expect(isSuspiciousLoginAttempt($email, '198.51.100.77'))->toBeTrue();

    DB::table('login_attempts')->where('email', $email)->delete();
});

test('suspicious login: recent failures from a different IP are flagged', function () {
    bootTestApp();

    $email = 'distributed-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    // The account's home IP has success history...
    insertLoginAttemptRow($email, '203.0.113.10', true, now()->subDays(10)->toDateTimeString());
    // ...but an attacker IP failed against this account yesterday.
    insertLoginAttemptRow($email, '198.51.100.66', false, now()->subDay()->toDateTimeString());

    // Distributed-failure signal trips even from the account's usual IP.
    expect(isSuspiciousLoginAttempt($email, '203.0.113.10'))->toBeTrue();
    expect(isSuspiciousLoginAttempt($email, '198.51.100.66'))->toBeTrue();

    // An unrelated clean account from the same home IP is NOT flagged: its
    // own history is clean and no one failed against it from other IPs.
    $clean = 'clean-test@example.com';
    DB::table('login_attempts')->where('email', $clean)->delete();
    insertLoginAttemptRow($clean, '203.0.113.10', true, now()->subDays(3)->toDateTimeString());
    expect(isSuspiciousLoginAttempt($clean, '203.0.113.10'))->toBeFalse();

    DB::table('login_attempts')->whereIn('email', [$email, $clean])->delete();
});

test('suspicious login: old failures from other IPs are outside the 30-day window', function () {
    bootTestApp();

    $email = 'oldfail-test@example.com';
    DB::table('login_attempts')->where('email', $email)->delete();

    insertLoginAttemptRow($email, '203.0.113.10', true, now()->subDays(40)->toDateTimeString());
    // Attacker gave up 31 days ago — stale history should not trip Signal 2.
    insertLoginAttemptRow($email, '198.51.100.66', false, now()->subDays(31)->toDateTimeString());

    expect(isSuspiciousLoginAttempt($email, '203.0.113.10'))->toBeFalse();

    DB::table('login_attempts')->where('email', $email)->delete();
});

test('suspicious login: email rotation from one IP is flagged at 3+ distinct emails', function () {
    bootTestApp();

    $rotatingIp = '192.0.2.99';
    $emails = ['rot-a-test@example.com', 'rot-b-test@example.com', 'rot-c-test@example.com'];
    DB::table('login_attempts')->whereIn('email', $emails)->delete();

    // Two distinct failed emails from this IP: below the rotation threshold.
    insertLoginAttemptRow($emails[0], $rotatingIp, false, now()->subHours(2)->toDateTimeString());
    insertLoginAttemptRow($emails[1], $rotatingIp, false, now()->subHours(1)->toDateTimeString());
    expect(isSuspiciousLoginAttempt($emails[0], $rotatingIp))->toBeFalse();

    // A third distinct failed email trips the credential-stuffing signal —
    // note the probe is for emails[0], proving the signal counts OTHER
    // accounts too, not just the one being logged into.
    insertLoginAttemptRow($emails[2], $rotatingIp, false, now()->subMinutes(30)->toDateTimeString());
    expect(isSuspiciousLoginAttempt($emails[0], $rotatingIp))->toBeTrue();

    DB::table('login_attempts')->whereIn('email', $emails)->delete();
    expect(isSuspiciousLoginAttempt($emails[0], $rotatingIp))->toBeFalse();
});

test('staff login/logout audit rows are written, and only for known events', function () {
    bootTestApp();

    // This harness boots without running migrations, so stand the audit table
    // up exactly as the migration defines it.
    if (!Schema::hasTable('order_activity_logs')) {
        Schema::create('order_activity_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_number', 191)->nullable();
            $table->string('action', 100);
            $table->string('actor_role', 100)->nullable();
            $table->string('actor_email', 191)->nullable();
            $table->text('summary')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    $email = 'audit-test@verify.test';
    DB::table('order_activity_logs')->where('actor_email', $email)->delete();

    recordStaffAccountActivity('login', 'Admin', $email);
    // A logout recorded from a dead session supplies a client timestamp and UA.
    recordStaffAccountActivity('logout', 'Cashier', strtoupper($email), '2026-09-11T00:00:00.000Z', 'VerifyAgent/1.0');

    $rows = DB::table('order_activity_logs')->where('actor_email', $email)->orderBy('id')->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->action)->toBe('account_login');
    expect($rows[0]->summary)->toBe('Administrator logged in');
    expect($rows[1]->action)->toBe('account_logout');
    expect($rows[1]->summary)->toBe('Cashier logged out');
    expect($rows[1]->actor_role)->toBe('Cashier');
    expect(json_decode($rows[1]->details, true))->toMatchArray([
        'event' => 'logout',
        'occurred_at' => '2026-09-11T00:00:00.000Z',
        'user_agent' => 'VerifyAgent/1.0',
    ]);

    // Email is normalized to lower case, and the event name is case-insensitive.
    recordStaffAccountActivity('LOGOUT', 'Admin', $email);
    expect(DB::table('order_activity_logs')->where('actor_email', $email)->where('action', 'account_logout')->count())->toBe(2);

    // Unattributable or unknown events are dropped rather than half-written.
    recordStaffAccountActivity('logout', '', $email);
    recordStaffAccountActivity('logout', 'Admin', '');
    recordStaffAccountActivity('something-else', 'Admin', $email);
    expect(DB::table('order_activity_logs')->where('actor_email', $email)->count())->toBe(3);

    DB::table('order_activity_logs')->where('actor_email', $email)->delete();
});

test('the staff session cookie is the primary token source, with a body fallback', function () {
    bootTestApp();

    $originalCookies = $_COOKIE;

    try {
        // No cookie: the request body is still honored for clients built
        // before the token moved into an HttpOnly cookie.
        $_COOKIE = [];
        expect(readStaffSessionTokenCookie())->toBeNull();
        expect(resolveStaffSessionRequestToken('legacy-body-token'))->toBe('legacy-body-token');
        expect(resolveStaffSessionRequestToken(''))->toBeNull();
        expect(resolveStaffSessionRequestToken(null))->toBeNull();

        // With a cookie present it wins over the body — that is the point of
        // moving the token out of page-script reach, so a body value cannot
        // override the browser's own session.
        $_COOKIE[STAFF_SESSION_COOKIE_NAME] = 'cookie-token';
        expect(readStaffSessionTokenCookie())->toBe('cookie-token');
        expect(resolveStaffSessionRequestToken('legacy-body-token'))->toBe('cookie-token');

        // Blank / whitespace-only cookies are treated as absent.
        $_COOKIE[STAFF_SESSION_COOKIE_NAME] = '   ';
        expect(readStaffSessionTokenCookie())->toBeNull();
        expect(resolveStaffSessionRequestToken(null))->toBeNull();
    } finally {
        $_COOKIE = $originalCookies;
    }
});

test('suspicious login: rotation signal ignores failures older than 24 hours', function () {
    bootTestApp();

    $rotatingIp = '192.0.2.100';
    $emails = ['rot-old-a@example.com', 'rot-old-b@example.com', 'rot-old-c@example.com'];
    DB::table('login_attempts')->whereIn('email', $emails)->delete();

    // Three distinct emails, but all failures are outside the 24h window.
    foreach ($emails as $index => $email) {
        insertLoginAttemptRow($email, $rotatingIp, false, now()->subHours(25 + $index)->toDateTimeString());
    }

    expect(isSuspiciousLoginAttempt($emails[0], $rotatingIp))->toBeFalse();

    DB::table('login_attempts')->whereIn('email', $emails)->delete();
});
