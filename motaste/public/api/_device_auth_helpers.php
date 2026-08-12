<?php

use Illuminate\Support\Facades\DB;

/**
 * Device recognition + login verification helpers.
 *
 * A device is identified by a stable client-generated token (persisted in the
 * browser) combined with the user agent and IP metadata. Trusted devices are
 * stored per staff account; unrecognized devices must confirm a short code
 * that is emailed to the account before the session is granted.
 */

function ensureTrustedDeviceTables(): void
{
    // At most once per request — avoids redundant introspection round-trips
    // (called by deviceIsTrusted, markTrustedDeviceSeen, createDeviceLoginCode).
    static $verified = false;
    if ($verified) {
        return;
    }

    if (!Schema::hasTable('trusted_devices')) {
        Schema::create('trusted_devices', function ($table) {
            $table->id();
            $table->string('email', 191);
            // SHA-256 hex fingerprint that already incorporates the account email.
            $table->string('fingerprint', 64);
            $table->string('device_label', 191)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('fingerprint', 'trusted_devices_fingerprint_unique');
            $table->index('email', 'trusted_devices_email_idx');
        });
    }

    if (!Schema::hasTable('login_verification_tokens')) {
        Schema::create('login_verification_tokens', function ($table) {
            $table->id();
            $table->string('email', 191);
            $table->string('fingerprint', 64);
            $table->string('code_hash', 191);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('email', 'login_verification_tokens_email_idx');
            $table->index('fingerprint', 'login_verification_tokens_fingerprint_idx');
        });
    }

    $verified = true;
}

function resolveClientIpAddress(): string
{
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $first = trim((string)$parts[0]);
        if ($first !== '') {
            return $first;
        }
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function resolveDeviceUserAgent(): string
{
    return trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/**
 * Build a stable per-device fingerprint for a staff account.
 *
 * The fingerprint deliberately excludes the IP address: IPs are volatile
 * (Wi-Fi/cellular switching, DHCP, NAT) and would otherwise invalidate a
 * trusted device on every login. The stable client token + user agent are
 * hashed; IP metadata is stored on the trusted_devices row for reference.
 */
function computeDeviceFingerprint(string $email, string $deviceToken = ''): string
{
    $raw = strtolower(trim($email)) . '|'
        . trim($deviceToken) . '|'
        . resolveDeviceUserAgent();

    return hash('sha256', $raw);
}

/**
 * Produce a short human-readable device label from the user agent.
 */
function resolveDeviceLabel(string $userAgent = ''): string
{
    $ua = $userAgent !== '' ? $userAgent : resolveDeviceUserAgent();

    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';
    elseif (stripos($ua, 'OPR/') !== false) $browser = 'Opera';

    $os = 'OS';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Mac OS X') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    return $browser . ' · ' . $os;
}

function deviceIsTrusted(string $email, string $fingerprint): bool
{
    ensureTrustedDeviceTables();

    return DB::table('trusted_devices')
        ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
        ->where('fingerprint', $fingerprint)
        ->exists();
}

/**
 * Register (or refresh) a trusted device for the account.
 */
function markTrustedDeviceSeen(string $email, string $fingerprint, ?string $label = null): void
{
    ensureTrustedDeviceTables();

    $email = strtolower(trim($email));
    $now = now()->toDateTimeString();

    $existing = DB::table('trusted_devices')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->where('fingerprint', $fingerprint)
        ->first();

    if ($existing) {
        DB::table('trusted_devices')->where('id', $existing->id)->update([
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);
        return;
    }

    DB::table('trusted_devices')->insert([
        'email' => $email,
        'fingerprint' => $fingerprint,
        'device_label' => $label !== null && $label !== '' ? $label : resolveDeviceLabel(),
        'user_agent' => resolveDeviceUserAgent(),
        'ip_address' => resolveClientIpAddress(),
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Create a single-use login verification code for an unrecognized device.
 * Returns the plaintext code (to be emailed); only a hash is persisted.
 */
function createDeviceLoginCode(string $email, string $fingerprint): string
{
    ensureTrustedDeviceTables();

    $email = strtolower(trim($email));
    $code = '';
    for ($i = 0; $i < 6; $i += 1) {
        $code .= (string)random_int(0, 9);
    }

    DB::table('login_verification_tokens')
        ->where('email', $email)
        ->where('fingerprint', $fingerprint)
        ->delete();

    DB::table('login_verification_tokens')->insert([
        'email' => $email,
        'fingerprint' => $fingerprint,
        'code_hash' => hash('sha256', $code),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10)->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $code;
}

/**
 * Validate a submitted code for the device. Single-use; deletes the token on
 * success and locks it after 5 failed attempts.
 */
function verifyDeviceLoginCode(string $email, string $fingerprint, string $code): bool
{
    ensureTrustedDeviceTables();

    $email = strtolower(trim($email));
    $token = DB::table('login_verification_tokens')
        ->where('email', $email)
        ->where('fingerprint', $fingerprint)
        ->orderBy('id', 'desc')
        ->first();

    if (!$token) {
        return false;
    }

    if (now()->greaterThan($token->expires_at)) {
        DB::table('login_verification_tokens')->where('id', $token->id)->delete();
        return false;
    }

    $hashedCode = hash('sha256', trim($code));
    if (!hash_equals((string)$token->code_hash, $hashedCode)) {
        $attempts = (int)($token->attempts ?? 0) + 1;
        if ($attempts >= 5) {
            DB::table('login_verification_tokens')->where('id', $token->id)->delete();
        } else {
            DB::table('login_verification_tokens')->where('id', $token->id)->update([
                'attempts' => $attempts,
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
        return false;
    }

    DB::table('login_verification_tokens')->where('id', $token->id)->delete();
    return true;
}
