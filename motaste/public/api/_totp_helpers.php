<?php

// Include-only helper: never reaches the browser as an entry script. When this
// file IS the request's entry script the request is direct HTTP access, so it
// is refused with 403. Legitimate includes (endpoints, console, tests) always
// have a different SCRIPT_FILENAME.
if (
    (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__)
    || (isset($_SERVER['PHP_SELF']) && realpath((string) $_SERVER['PHP_SELF']) === __FILE__)
) {
    http_response_code(403);
    exit;
}

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP (RFC 6238) helpers for the staff portal's authenticator-app 2FA.
 *
 * The shared secret is stored base32-encoded and encrypted at rest with the
 * app key (Crypt), so a database leak does not immediately expose usable
 * secrets. Codes are HMAC-SHA1, 6 digits, 30-second period, validated with a
 * +/-1 time-step window and a replay guard (the last accepted time-step is
 * stored; a code for an equal or older step is rejected).
 */

function ensureTotpSecretsTable(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    if (!Schema::hasTable('totp_secrets')) {
        Schema::create('totp_secrets', function ($table) {
            $table->id();
            $table->string('email', 191);
            // Base32 secret, encrypted at rest with APP_KEY (Crypt).
            $table->text('secret');
            $table->unsignedBigInteger('last_used_step')->default(0);
            $table->unsignedTinyInteger('enabled')->default(0);
            $table->timestamps();

            $table->unique('email', 'totp_secrets_email_unique');
        });
    }

    $verified = true;
}

/**
 * RFC 4648 base32 encoder (no padding).
 */
function base32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $encoded = '';
    for ($i = 0; $i < strlen($binary); $i += 5) {
        $chunk = substr($binary, $i, 5);
        $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }

    return $encoded;
}

/**
 * RFC 4648 base32 decoder (tolerates spaces/lowercase, ignores invalid chars).
 */
function base32Decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper((string) preg_replace('/[^A-Z2-7]/i', '', $base32));

    $binary = '';
    foreach (str_split($base32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $decoded = '';
    for ($i = 0; $i + 8 <= strlen($binary); $i += 8) {
        $decoded .= chr(bindec(substr($binary, $i, 8)));
    }

    return $decoded;
}

/**
 * Generate a fresh 160-bit TOTP secret (base32 encoded).
 */
function generateTotpSecret(): string
{
    return base32Encode(random_bytes(20));
}

/**
 * The otpauth:// URI a staff member pastes/scans into an authenticator app.
 */
function buildOtpauthUri(string $email, string $secretBase32, string $issuer = 'MOTASTE'): string
{
    $label = $issuer . ':' . strtolower(trim($email));
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . $email
        . '?secret=' . rawurlencode($secretBase32)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * RFC 6238: HMAC-SHA1 6-digit code for a given 30-second step.
 */
function totpCodeAt(string $secretBase32, int $step): string
{
    $key = base32Decode($secretBase32);
    if ($key === '') {
        return '';
    }

    $counter = max(0, $step);
    $high = ($counter >> 32) & 0xFFFFFFFF;
    $low = $counter & 0xFFFFFFFF;
    $packed = pack('N2', $high, $low);

    $hash = hash_hmac('sha1', $packed, $key, true);

    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);

    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * The pending-or-active totp_secrets row for an account (lowercased lookup).
 */
function getTotpSettingRow(string $email): ?object
{
    ensureTotpSecretsTable();

    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    return DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();
}

/**
 * Whether the account currently requires an authenticator code at login.
 */
function totpEnabledFor(string $email): bool
{
    $row = getTotpSettingRow($email);

    return $row !== null && (int) $row->enabled === 1;
}

/**
 * Decrypt a stored secret; returns '' on any failure (mismatched key, etc).
 */
function decryptTotpSecret(?string $encrypted): string
{
    if ($encrypted === null || $encrypted === '') {
        return '';
    }

    try {
        return (string) Crypt::decryptString($encrypted);
    } catch (Throwable $error) {
        error_log('decryptTotpSecret failed: ' . $error->getMessage());
        return '';
    }
}

/**
 * Store (or refresh) a pending secret for the account during setup. The row is
 * kept with enabled = 0 until the confirmation step validates a live code.
 */
function storePendingTotpSecret(string $email, string $secretBase32): void
{
    ensureTotpSecretsTable();

    $email = strtolower(trim($email));
    $now = now();

    DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->delete();

    DB::table('totp_secrets')->insert([
        'email' => $email,
        'secret' => Crypt::encryptString($secretBase32),
        'last_used_step' => 0,
        'enabled' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Activate a pending secret once the setup code has been confirmed. The
 * confirming step is recorded as last_used_step so that exact code cannot be
 * replayed as a login code.
 */
function activateTotpSecret(string $email, string $secretBase32): void
{
    ensureTotpSecretsTable();

    $email = strtolower(trim($email));
    $step = intdiv(time(), 30);

    DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->update([
            'secret' => Crypt::encryptString($secretBase32),
            'last_used_step' => $step,
            'enabled' => 1,
            'updated_at' => now(),
        ]);
}

/**
 * Disable TOTP for an account (removes the secret entirely).
 */
function disableTotpFor(string $email): void
{
    ensureTotpSecretsTable();

    DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
        ->delete();
}

/**
 * Re-key the TOTP row when an admin changes the account's email, so the
 * authenticator enrollment follows the account instead of orphaning the secret.
 */
function totpRekeyEmail(string $oldEmail, string $newEmail): void
{
    ensureTotpSecretsTable();

    $oldEmail = strtolower(trim($oldEmail));
    $newEmail = strtolower(trim($newEmail));
    if ($oldEmail === '' || $newEmail === '' || $oldEmail === $newEmail) {
        return;
    }

    $existing = DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [$newEmail])
        ->first();
    if ($existing) {
        // The new email already has its own enrollment — drop the relocated one
        // rather than fighting over the unique key.
        DB::table('totp_secrets')
            ->whereRaw('LOWER(email) = ?', [$oldEmail])
            ->delete();

        return;
    }

    DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [$oldEmail])
        ->update([
            'email' => $newEmail,
            'updated_at' => now(),
        ]);
}

/**
 * Validate a live authenticator code during enrollment against the PENDING
 * secret (enabled = 0). No replay guard here — the setup step is single-shot:
 * once a correct code is seen the secret is activated in the same request.
 */
function confirmPendingTotpSecret(string $email, string $code): bool
{
    ensureTotpSecretsTable();

    $email = strtolower(trim($email));
    $row = DB::table('totp_secrets')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();
    if (!$row || (int) $row->enabled !== 0) {
        return false;
    }

    $secret = decryptTotpSecret((string) ($row->secret ?? ''));
    if ($secret === '') {
        return false;
    }

    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $nowStep = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset += 1) {
        $expected = totpCodeAt($secret, $nowStep + $offset);
        if ($expected !== '' && hash_equals($expected, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Validate an authenticator code for the account. Accepts the current step and
 * the two neighbouring steps to tolerate clock drift, and rejects any code for
 * a time-step already used (replay protection via last_used_step).
 */
function verifyTotpCode(string $email, string $code): bool
{
    ensureTotpSecretsTable();

    $email = strtolower(trim($email));
    $row = getTotpSettingRow($email);
    if (!$row || (int) $row->enabled !== 1) {
        return false;
    }

    $secret = decryptTotpSecret((string) ($row->secret ?? ''));
    if ($secret === '') {
        return false;
    }

    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $lastUsed = (int) ($row->last_used_step ?? 0);
    $nowStep = intdiv(time(), 30);

    for ($offset = -1; $offset <= 1; $offset += 1) {
        $step = $nowStep + $offset;
        if ($step <= $lastUsed) {
            continue;
        }

        $expected = totpCodeAt($secret, $step);
        if ($expected !== '' && hash_equals($expected, $code)) {
            DB::table('totp_secrets')
                ->where('id', $row->id)
                ->update([
                    'last_used_step' => $step,
                    'updated_at' => now(),
                ]);

            return true;
        }
    }

    return false;
}