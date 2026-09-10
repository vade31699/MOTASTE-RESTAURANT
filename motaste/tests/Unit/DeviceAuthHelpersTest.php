<?php

use Illuminate\Support\Facades\DB;

/**
 * Tests for the device-recognition / login-verification helpers
 * (public/api/_device_auth_helpers.php): user-agent labeling, trusted-device
 * registration, and single-use verification codes. Boots the app on the
 * in-memory testing database like the other helper tests, so production data
 * is never touched.
 */
function bootDeviceAuthTestApp(): void
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

    require_once __DIR__ . '/../../public/api/_device_auth_helpers.php';

    ensureTrustedDeviceTables();
    ensureAppSettingsTable();
}

// Boot before the first test runs, not inside it: the app bootstrap registers
// global error/exception handlers, and PHPUnit marks whichever test triggers
// the boot as "risky" (did not remove its own error handlers) if it happens
// mid-test. beforeAll runs outside per-test handler accounting.
beforeAll(function () {
    bootDeviceAuthTestApp();
});

test('user-agent labels detect Opera, Edge, Chrome, and Safari correctly', function () {
    bootDeviceAuthTestApp();

    // Opera UAs contain 'Chrome/' and 'Safari/' — the OPR/ marker must win.
    expect(resolveDeviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 OPR/77.0.4054.90'))
        ->toBe('Opera · Windows');

    // Edge UAs contain 'Chrome/' too — the Edg/ marker must win.
    expect(resolveDeviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'))
        ->toBe('Edge · Windows');

    expect(resolveDeviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'))
        ->toBe('Chrome · Windows');

    expect(resolveDeviceLabel('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'))
        ->toBe('Safari · macOS');
});

test('trusted devices are registered once and refreshed without duplicates', function () {
    bootDeviceAuthTestApp();

    $email = 'device-test@example.com';
    $fingerprint = computeDeviceFingerprint($email, 'device-token-abc');

    DB::table('trusted_devices')->where('email', $email)->delete();

    expect(deviceIsTrusted($email, $fingerprint))->toBeFalse();

    // First call registers the device…
    markTrustedDeviceSeen($email, $fingerprint);
    expect(deviceIsTrusted($email, $fingerprint))->toBeTrue();
    expect(DB::table('trusted_devices')->where('email', $email)->count())->toBe(1);

    // …a second call (e.g. a concurrent login) must not throw or duplicate.
    markTrustedDeviceSeen($email, $fingerprint);
    expect(DB::table('trusted_devices')->where('email', $email)->count())->toBe(1);

    // A row that already exists (concurrent insert committed first) is
    // refreshed instead of tripping the unique fingerprint constraint.
    $otherFp = computeDeviceFingerprint($email, 'device-token-xyz');
    DB::table('trusted_devices')->insert([
        'email' => $email,
        'fingerprint' => $otherFp,
        'last_seen_at' => now()->subDay()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    markTrustedDeviceSeen($email, $otherFp);
    expect(DB::table('trusted_devices')->where('fingerprint', $otherFp)->value('last_seen_at'))
        ->not->toBeNull();
    expect(DB::table('trusted_devices')->where('email', $email)->count())->toBe(2);

    DB::table('trusted_devices')->where('email', $email)->delete();
});

test('verification codes are single-use and self-destruct after 5 failed attempts', function () {
    bootDeviceAuthTestApp();

    $email = 'code-test@example.com';
    $fingerprint = computeDeviceFingerprint($email, 'code-device-token');
    DB::table('login_verification_tokens')->where('email', $email)->delete();

    $code = createDeviceLoginCode($email, $fingerprint);
    expect(strlen($code))->toBe(6);
    expect(ctype_digit($code))->toBeTrue();

    // Only a hash is persisted, never the plaintext code.
    $stored = DB::table('login_verification_tokens')->where('email', $email)->first();
    expect($stored->code_hash)->not->toBe($code);

    // The correct code validates once…
    expect(verifyDeviceLoginCode($email, $fingerprint, $code))->toBeTrue();

    // …and the same code is rejected on replay (single-use).
    expect(verifyDeviceLoginCode($email, $fingerprint, $code))->toBeFalse();

    // Wrong guesses increment the counter; the 5th locks the token.
    $code2 = createDeviceLoginCode($email, $fingerprint);
    $wrongCode = str_pad((string)(((int)$code2 + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    expect($wrongCode)->not->toBe($code2);

    foreach (range(1, 4) as $attempt) {
        expect(verifyDeviceLoginCode($email, $fingerprint, $wrongCode))->toBeFalse();
        $row = DB::table('login_verification_tokens')->where('email', $email)->first();
        expect((int)$row->attempts)->toBe($attempt);
    }
    // The 5th wrong guess deletes the token.
    expect(verifyDeviceLoginCode($email, $fingerprint, $wrongCode))->toBeFalse();
    expect(DB::table('login_verification_tokens')->where('email', $email)->exists())->toBeFalse();

    DB::table('login_verification_tokens')->where('email', $email)->delete();
});

test('expired verification codes are rejected and cleaned up', function () {
    bootDeviceAuthTestApp();

    $email = 'expired-code-test@example.com';
    $fingerprint = computeDeviceFingerprint($email, 'expired-token');
    DB::table('login_verification_tokens')->where('email', $email)->delete();

    $code = createDeviceLoginCode($email, $fingerprint);
    DB::table('login_verification_tokens')
        ->where('email', $email)
        ->update(['expires_at' => now()->subMinute()->toDateTimeString()]);

    expect(verifyDeviceLoginCode($email, $fingerprint, $code))->toBeFalse();
    expect(DB::table('login_verification_tokens')->where('email', $email)->exists())->toBeFalse();
});

test('trust device toggle defaults to enabled and persists changes', function () {
    bootDeviceAuthTestApp();

    DB::table('app_settings')->where('key', 'trust_device_enabled')->delete();

    // Defaults to enabled so existing deployments keep current behavior until
    // the Admin explicitly turns the feature off.
    expect(isTrustDeviceEnabled())->toBeTrue();

    // Disabling persists and is read back…
    setTrustDeviceEnabled(false);
    expect(isTrustDeviceEnabled())->toBeFalse();

    // …and re-enabling restores the trusted-device fast path.
    setTrustDeviceEnabled(true);
    expect(isTrustDeviceEnabled())->toBeTrue();

    DB::table('app_settings')->where('key', 'trust_device_enabled')->delete();
});