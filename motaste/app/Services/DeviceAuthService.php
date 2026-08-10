<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device recognition + login verification helpers.
 *
 * A device is identified by a stable client-generated token (persisted in the
 * browser) combined with the user agent and IP metadata. Trusted devices are
 * stored per staff account; unrecognized devices must confirm a short code
 * that is emailed to the account before the session is granted.
 * Ported from the legacy public/api/_device_auth_helpers.php.
 */
class DeviceAuthService
{
    public static function resolveClientIpAddress(): string
    {
        $forwarded = trim((string) (request()->header('X-Forwarded-For') ?? ''));
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $first = trim((string) $parts[0]);
            if ($first !== '') {
                return $first;
            }
        }

        return trim((string) (request()->ip() ?? ''));
    }

    public static function resolveDeviceUserAgent(): string
    {
        return trim((string) (request()->userAgent() ?? ''));
    }

    /**
     * Build a stable per-device fingerprint for a staff account.
     *
     * The fingerprint deliberately excludes the IP address: IPs are volatile
     * (Wi-Fi/cellular switching, DHCP, NAT) and would otherwise invalidate a
     * trusted device on every login. The stable client token + user agent are
     * hashed; IP metadata is stored on the trusted_devices row for reference.
     */
    public static function computeFingerprint(string $email, string $deviceToken = ''): string
    {
        $raw = strtolower(trim($email)).'|'
            .trim($deviceToken).'|'
            .self::resolveDeviceUserAgent();

        return hash('sha256', $raw);
    }

    /**
     * Produce a short human-readable device label from the user agent.
     */
    public static function resolveDeviceLabel(string $userAgent = ''): string
    {
        $ua = $userAgent !== '' ? $userAgent : self::resolveDeviceUserAgent();

        $browser = 'Browser';
        if (stripos($ua, 'Edg/') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'Chrome/') !== false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Firefox/') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'Safari/') !== false) {
            $browser = 'Safari';
        } elseif (stripos($ua, 'OPR/') !== false) {
            $browser = 'Opera';
        }

        $os = 'OS';
        if (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = 'iOS';
        } elseif (stripos($ua, 'Mac OS X') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }

        return $browser.' · '.$os;
    }

    public static function deviceIsTrusted(string $email, string $fingerprint): bool
    {
        return DB::table('trusted_devices')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * Register (or refresh) a trusted device for the account.
     */
    public static function markTrustedSeen(string $email, string $fingerprint, ?string $label = null): void
    {
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
            'device_label' => $label !== null && $label !== '' ? $label : self::resolveDeviceLabel(),
            'user_agent' => self::resolveDeviceUserAgent(),
            'ip_address' => self::resolveClientIpAddress(),
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
    public static function createLoginCode(string $email, string $fingerprint): string
    {
        $email = strtolower(trim($email));
        $code = '';
        for ($i = 0; $i < 6; $i += 1) {
            $code .= (string) random_int(0, 9);
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
     * Validate a submitted code for the device. Single-use; deletes the token
     * on success and locks it after 5 failed attempts.
     */
    public static function verifyLoginCode(string $email, string $fingerprint, string $code): bool
    {
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
        if (!hash_equals((string) $token->code_hash, $hashedCode)) {
            $attempts = (int) ($token->attempts ?? 0) + 1;
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
}
