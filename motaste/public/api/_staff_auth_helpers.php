<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;

/**
 * Shared server-side staff authentication + system enhancement helpers.
 *
 * Staff authentication reuses the PHP-native session that authenticate_staff.php
 * already populates with $_SESSION['staff']. This helper adds:
 *   - a persistent session cookie lifetime (so "stay logged in" survives restarts)
 *   - a requireStaffAuth() gate for staff-only endpoints
 *   - brute-force login rate limiting (login_attempts table)
 *   - on-demand schema ensures for enhancement columns/tables
 *   - an API event audit log, loyalty helpers, and low-stock email alerts
 */

/*
| Staff-login brute-force limits and session lifetimes.
|
| Each setting has a compiled-in default (the *_DEFAULT constants) and is
| overridable per deployment with the matching env var. They are resolved
| through staffEnvLimit(), which ignores blank/non-numeric values and clamps to
| at least 1 so a misconfiguration can never disable a protection.
|
|   STAFF_LOGIN_MAX_ATTEMPTS        - failed attempts before an account locks
|   STAFF_LOGIN_LOCKOUT_MINUTES     - window those failures are counted over
|   STAFF_LOGIN_IP_MAX_ATTEMPTS     - failed attempts before an IP locks
|   STAFF_LOGIN_IP_LOCKOUT_MINUTES  - window for the IP-scoped counter
|   STAFF_LOGIN_CAPTCHA_THRESHOLD   - failures before the CAPTCHA is demanded
|   STAFF_SESSION_LIFETIME_SECONDS  - persistent staff session cookie lifetime
|   STAFF_SESSION_TOKEN_TTL_DAYS    - lifetime of an issued session token
*/
const STAFF_LOGIN_MAX_ATTEMPTS_DEFAULT = 5;
const STAFF_LOGIN_LOCKOUT_MINUTES_DEFAULT = 2;
const STAFF_LOGIN_IP_MAX_ATTEMPTS_DEFAULT = 20;
const STAFF_LOGIN_IP_LOCKOUT_MINUTES_DEFAULT = 2;

// Default number of recent failed attempts (account- or IP-scoped, within the
// lockout window) after which a CAPTCHA is demanded. With the default 3, the
// 4th submit is the first one that demands a completed CAPTCHA — the first
// three wrong-password/email attempts get a plain invalid-credentials
// rejection. A CAPTCHA can also be required earlier (even on the first submit)
// when isSuspiciousLoginAttempt() flags the login.
const STAFF_LOGIN_CAPTCHA_THRESHOLD_DEFAULT = 3;

const STAFF_SESSION_LIFETIME_SECONDS_DEFAULT = 30 * 24 * 60 * 60; // 30 days
const STAFF_SESSION_TOKEN_TTL_DAYS_DEFAULT = 30;

/**
 * Read a staff security setting from its env var, falling back to $default
 * when the var is blank/non-numeric and clamping to at least $min so a
 * misconfiguration cannot weaken (or accidentally disable) a protection.
 */
function staffEnvLimit(string $envKey, int $default, int $min = 1): int
{
    $configured = env($envKey, $default);

    $value = is_numeric($configured) ? (int)$configured : $default;

    return max($min, $value);
}

/** Failed attempts (per account, within the lockout window) before lockout. */
function staffLoginMaxAttempts(): int
{
    return staffEnvLimit('STAFF_LOGIN_MAX_ATTEMPTS', STAFF_LOGIN_MAX_ATTEMPTS_DEFAULT);
}

/** Minutes an account's failed attempts are counted over. */
function staffLoginLockoutMinutes(): int
{
    return staffEnvLimit('STAFF_LOGIN_LOCKOUT_MINUTES', STAFF_LOGIN_LOCKOUT_MINUTES_DEFAULT);
}

/** Failed attempts (per IP, across all accounts) before lockout. */
function staffLoginIpMaxAttempts(): int
{
    return staffEnvLimit('STAFF_LOGIN_IP_MAX_ATTEMPTS', STAFF_LOGIN_IP_MAX_ATTEMPTS_DEFAULT);
}

/** Minutes an IP's failed attempts are counted over. */
function staffLoginIpLockoutMinutes(): int
{
    return staffEnvLimit('STAFF_LOGIN_IP_LOCKOUT_MINUTES', STAFF_LOGIN_IP_LOCKOUT_MINUTES_DEFAULT);
}

/**
 * Resolve the CAPTCHA threshold from the STAFF_LOGIN_CAPTCHA_THRESHOLD env var
 * so each deployment can tune how quickly the client-visible CAPTCHA appears.
 * Falls back to STAFF_LOGIN_CAPTCHA_THRESHOLD_DEFAULT and is clamped to at
 * least 1 so a blank/invalid setting cannot silently disarm the gate.
 */
function staffLoginCaptchaThreshold(): int
{
    return staffEnvLimit('STAFF_LOGIN_CAPTCHA_THRESHOLD', STAFF_LOGIN_CAPTCHA_THRESHOLD_DEFAULT);
}

/** Lifetime of the persistent staff session cookie, in seconds. */
function staffSessionLifetimeSeconds(): int
{
    return staffEnvLimit('STAFF_SESSION_LIFETIME_SECONDS', STAFF_SESSION_LIFETIME_SECONDS_DEFAULT);
}

/** Lifetime of an issued staff session token, in days. */
function staffSessionTokenTtlDays(): int
{
    return staffEnvLimit('STAFF_SESSION_TOKEN_TTL_DAYS', STAFF_SESSION_TOKEN_TTL_DAYS_DEFAULT);
}

/**
 * Ensure every schema addition used by the enhancement features exists.
 * Follows the codebase convention of creating tables/columns on demand so new
 * deployments work even before migrations have run.
 */
function ensureStaffEnhancementSchema(): void
{
    // Run the introspection queries at most once per request, and remember the
    // successful result in the cache so logins don't pay for ~15 schema queries
    // on every request (these tables are also managed by migrations).
    static $verifiedThisRequest = false;
    if ($verifiedThisRequest) {
        return;
    }

    try {
        if (Cache::has('motaste_schema_ok_v1')) {
            $verifiedThisRequest = true;
            return;
        }
    } catch (Throwable $cacheError) {
        // Cache unavailable (e.g. fresh deployment) — fall through to the full check.
    }

    try {
        if (!Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191)->index();
                $table->string('ip_address', 45)->nullable();
                $table->boolean('success')->default(false);
                $table->timestamp('attempted_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('api_event_logs')) {
            Schema::create('api_event_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event', 100)->index();
                $table->text('details')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('staff_session_tokens')) {
            Schema::create('staff_session_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('role', 100)->nullable();
                // SHA-256 hash of the opaque bearer token (never stored in plaintext).
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index('email', 'staff_session_tokens_email_idx');
            });
        }

        if (!Schema::hasTable('inventory_items')) {
            return;
        }

        if (!Schema::hasColumn('inventory_items', 'unit_cost')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->decimal('unit_cost', 10, 2)->default(0)->after('price');
            });
        }
        if (!Schema::hasColumn('inventory_items', 'reorder_level')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->integer('reorder_level')->default(0)->after('unit_cost');
            });
        }
        if (!Schema::hasColumn('inventory_items', 'is_available')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->boolean('is_available')->default(true)->after('reorder_level');
            });
        }

        // NOTE: the `staff.last_active_at` column used by the online-status
        // heartbeat is intentionally NOT created here. It is managed by the
        // Laravel migration only, so web requests can never issue ALTER TABLE
        // against the production `staff` table (which could lock under load).
        if (!Schema::hasTable('staff')) {
            return;
        }

        if (!Schema::hasTable('orders')) {
            return;
        }
        if (!Schema::hasColumn('orders', 'customer_email')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('customer_email', 191)->nullable()->after('delivery_address');
            });
        }
        if (!Schema::hasColumn('orders', 'customer_phone')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('customer_phone', 40)->nullable()->after('customer_email');
            });
        }
        if (!Schema::hasColumn('orders', 'discount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('discount', 10, 2)->default(0)->after('total_amount');
            });
        }
        if (!Schema::hasColumn('orders', 'cancelled_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable();
            });
        }
        if (!Schema::hasColumn('orders', 'refunded_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('refunded_at')->nullable();
            });
        }
        // Schema verified successfully — remember it briefly so subsequent
        // requests skip the introspection round-trips.
        try {
            Cache::put('motaste_schema_ok_v1', true, 600);
        } catch (Throwable $cacheError) {
            // Best effort.
        }
        $verifiedThisRequest = true;
    } catch (Throwable $error) {
        // Schema changes must never block a request; migrations apply on deploy.
        error_log('ensureStaffEnhancementSchema failed: ' . $error->getMessage());
    }
}

/**
 * Start the PHP-native session with a persistent cookie so the staff session
 * survives browser restarts (mirrors the existing "stay logged in" behavior).
 */
function ensureStaffAuthSession(): void
{
    if (!function_exists('sendSecurityHeaders')) {
        require_once __DIR__ . '/_security_headers.php';
    }
    sendSecurityHeaders();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => staffSessionLifetimeSeconds(),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/**
 * Returns the authenticated staff array (role/email/name) or null.
 *
 * SECURITY: a valid PHP session alone is no longer sufficient. The caller must
 * also hold a valid bearer token (HttpOnly cookie, or legacy body fallback)
 * whose account/role matches the session. This closes the case where a browser
 * keeps a stale PHP session cookie (e.g. after the bearer token expired or was
 * revoked) and silently stays authenticated on every staff-only request.
 *
 * If the session is missing but the bearer token is valid, the PHP session is
 * rehydrated from the token so the rest of the request sees a normal session.
 */
function requireStaffAuth(): ?array
{
    ensureStaffAuthSession();

    $session = !empty($_SESSION['staff']) && is_array($_SESSION['staff'])
        ? $_SESSION['staff']
        : null;

    $sessionEmail = $session ? (string)($session['email'] ?? '') : '';
    $sessionRole  = $session ? (string)($session['role']  ?? '') : '';

    // The bearer token (HttpOnly cookie preferred, body as legacy fallback).
    $bearerToken   = resolveStaffSessionRequestToken();
    $bearerIdentity = $bearerToken !== null ? resolveStaffSessionToken($bearerToken) : null;

    // Case A: both session and bearer present — they must agree exactly.
    if ($session && $bearerIdentity) {
        $bearerEmail = (string)($bearerIdentity['email'] ?? '');
        $bearerRole  = (string)($bearerIdentity['role']  ?? '');

        if (!hash_equals(strtolower($sessionEmail), strtolower($bearerEmail))
            || !hash_equals(strtolower($sessionRole), strtolower($bearerRole))) {
            // Mismatch: session says one thing, bearer token says another.
            // Tear down the PHP session and require a fresh login — do NOT
            // trust either side on its own.
            require_once __DIR__ . '/csrf_guard.php';
            if (function_exists('setStaffSessionTokenCookie')) {
                setStaffSessionTokenCookie(null);
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                session_destroy();
            }
            abortStaffAuthRequired();
        }

        logStaffApiRequest((string)($_SERVER['REQUEST_URI'] ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api'))));
        static $lastTouchEmail = '';
        if ($sessionEmail !== $lastTouchEmail) {
            $lastTouchEmail = $sessionEmail;
            touchStaffLastActive($sessionEmail);
        }
        return $session;
    }

    // Case B: session only. The bearer token is missing/expired — the session
    // is stale. Revoke the now-orphaned session and block.
    if ($session && !$bearerIdentity) {
        if (function_exists('setStaffSessionTokenCookie')) {
            setStaffSessionTokenCookie(null);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        abortStaffAuthRequired();
    }

    // Case C: bearer token only (PHP session missing/expired mid-flight).
    // Rehydrate the session from the token so the rest of the request sees a
    // normal session, but only after confirming the account still exists with
    // the same role.
    if (!$session && $bearerIdentity) {
        $email  = (string)($bearerIdentity['email'] ?? '');
        $role   = (string)($bearerIdentity['role']  ?? '');

        if ($email !== '') {
            $account = findStaffAuthAccount($email);
            if ($account && strtolower(trim((string)($account->role ?? ''))) === strtolower($role)) {
                $_SESSION['staff'] = [
                    'role'       => $role,
                    'email'      => $email,
                    'name'       => trim((string)($account->full_name ?? '')),
                    'logged_in_at' => now()->toDateTimeString(),
                ];

                logStaffApiRequest((string)($_SERVER['REQUEST_URI'] ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api'))));
                touchStaffLastActive($email);

                return $_SESSION['staff'];
            }
        }

        // Token valid but account/role gone — revoke and block.
        if ($bearerToken !== null) {
            revokeStaffSessionToken($bearerToken);
        }
        if (function_exists('setStaffSessionTokenCookie')) {
            setStaffSessionTokenCookie(null);
        }
        abortStaffAuthRequired();
    }

    // Case D: neither — no authentication at all.
    return null;
}

/**
 * Record the staff member's last activity timestamp (heartbeat). Best-effort;
 * never blocks the request.
 */
function touchStaffLastActive(string $email): void
{
    if (trim($email) === '') {
        return;
    }

    // NOTE: this runs on the hot path of every staff API request, so it must
    // NEVER trigger schema DDL. The `last_active_at` column is created by the
    // migration (and the on-demand schema check); until it exists the UPDATE
    // simply fails here and is logged, and the online indicator stays empty.
    try {
        // The Admin now lives in its own table; update whichever table holds
        // this account so the heartbeat works for every role.
        $table = staffAccountTableForEmail($email) ?? 'staff';
        DB::table($table)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->update(['last_active_at' => now()->toDateTimeString()]);
    } catch (Throwable $error) {
        error_log('touchStaffLastActive failed: ' . $error->getMessage());
    }
}

/**
 * Staff accounts that have been active within the last 5 minutes — used to
 * render the "online now" indicator in the credentials section.
 */
function getOnlineStaffAccounts(): array
{
    // No schema DDL here either: if `last_active_at` is missing the query
    // fails and we return an empty online list until the migration runs.
    try {
        $threshold = now()->subMinutes(5)->toDateTimeString();

        // Admin rows live in `admins`, everyone else in `staff`; merge both.
        $tables = ['staff'];
        if (Schema::hasTable('admins')) {
            $tables[] = 'admins';
        }

        $online = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)
                ->where('last_active_at', '>=', $threshold)
                ->orderByDesc('last_active_at')
                ->get(['email', 'full_name', 'role', 'last_active_at'])
                ->all();

            foreach ($rows as $row) {
                $role = trim((string)($row->role ?? ''));
                if ($role === '' && $table === 'admins') {
                    $role = 'Admin';
                }
                $online[] = [
                    'email' => (string)($row->email ?? ''),
                    'name' => trim((string)($row->full_name ?? '')) ?: 'Staff',
                    'role' => $role ?: 'Staff',
                    'last_active_at' => (string)($row->last_active_at ?? ''),
                ];
            }
        }
        return $online;
    } catch (Throwable $error) {
        error_log('getOnlineStaffAccounts failed: ' . $error->getMessage());
        return [];
    }
}

/**
 * Clear a staff member's last-active timestamp so they drop out of the
 * "online now" list immediately (e.g. on logout) instead of lingering for
 * the 5-minute activity window. Best-effort; never blocks the request.
 */
function markStaffOffline(string $email): void
{
    if (trim($email) === '') {
        return;
    }

    // Same no-DDL constraint as touchStaffLastActive(): if `last_active_at`
    // does not exist yet the UPDATE fails silently and the online list stays
    // unchanged until the migration runs.
    try {
        $table = staffAccountTableForEmail($email) ?? 'staff';
        DB::table($table)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->update(['last_active_at' => null]);
    } catch (Throwable $error) {
        error_log('markStaffOffline failed: ' . $error->getMessage());
    }
}

/**
 * Structured request log for gated API endpoints. Best-effort; never blocks.
 */
function logStaffApiRequest(string $endpoint): void
{
    static $logged = false;
    if ($logged) {
        return; // one entry per request lifecycle
    }
    $logged = true;

    try {
        logApiEvent('api_request', [
            'endpoint' => $endpoint,
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'ip' => function_exists('resolveClientIpAddress') ? resolveClientIpAddress() : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $error) {
        error_log('logStaffApiRequest failed: ' . $error->getMessage());
    }
}

/**
 * Emit the standard 401 JSON response for missing/invalid staff auth.
 */
function abortStaffAuthRequired(): void
{
    logStaffApiRequest((string)($_SERVER['REQUEST_URI'] ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api'))));

    if (!headers_sent()) {
        http_response_code(401);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Staff authentication required. Please log in again.',
        'authRequired' => true,
    ]);
    exit;
}

/**
 * True when the account+IP has exceeded the failed-attempt budget recently.
 */
function isLoginRateLimited(string $email): bool
{
    ensureStaffEnhancementSchema();

    $since = now()->subMinutes(staffLoginLockoutMinutes());
    $count = DB::table('login_attempts')
        ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
        ->where('success', false)
        ->where('attempted_at', '>=', $since->toDateTimeString())
        ->count();

    return $count >= staffLoginMaxAttempts();
}

function recordLoginAttempt(string $email, bool $success): void
{
    ensureStaffEnhancementSchema();

    try {
        DB::table('login_attempts')->insert([
            'email' => strtolower(trim($email)),
            'ip_address' => function_exists('resolveClientIpAddress') ? resolveClientIpAddress() : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'success' => $success,
            'attempted_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $error) {
        error_log('recordLoginAttempt failed: ' . $error->getMessage());
    }

    if ($success) {
        clearLoginAttempts($email);
    }
}function clearLoginAttempts(string $email): void
{
    ensureStaffEnhancementSchema();

    try {
        DB::table('login_attempts')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->delete();
    } catch (Throwable $error) {
        // Best effort.
    }
}

/**
 * Check whether an IP address has exceeded the brute-force login threshold.
 * This prevents an attacker from rotating emails to bypass per-account lockout.
 */
function isLoginIpRateLimited(string $ipAddress): bool
{
    ensureStaffEnhancementSchema();

    try {
        $since = now()->subMinutes(staffLoginIpLockoutMinutes());
        $count = DB::table('login_attempts')
            ->where('ip_address', $ipAddress)
            ->where('success', false)
            ->where('attempted_at', '>=', $since->toDateTimeString())
            ->count();

        return $count >= staffLoginIpMaxAttempts();
    } catch (Throwable $error) {
        error_log('isLoginIpRateLimited failed: ' . $error->getMessage());
        return false;
    }
}

/**
 * Detect a "suspicious" login attempt — a request whose context looks unlike
 * the account's normal login pattern, even when the attempt budget has not
 * been exhausted. Used to demand a CAPTCHA earlier than raw failure counts.
 *
 * Signals (queried from login_attempts):
 *  1. The account has successful logins on record, but none from the current
 *     IP → first-time IP for this account (new device/network, or an attacker).
 *  2. The account had failed attempts from a DIFFERENT IP recently → possible
 *     distributed attack against one account.
 *  3. The current IP recently failed logins for MULTIPLE different emails →
 *     credential-stuffing / email-rotation pattern.
 *
 * Best-effort: on any DB error it returns false so logins stay available.
 */
function isSuspiciousLoginAttempt(string $email, string $ipAddress): bool
{
    ensureStaffEnhancementSchema();

    try {
        $email = strtolower(trim($email));
        $since = now()->subDays(30)->toDateTimeString();

        // Signal 1: known-good logins exist, but never from this IP.
        $hasSuccessfulLogins = DB::table('login_attempts')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('success', true)
            ->exists();

        if ($hasSuccessfulLogins) {
            $hasSuccessFromThisIp = DB::table('login_attempts')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('success', true)
                ->where('ip_address', $ipAddress)
                ->exists();

            if (!$hasSuccessFromThisIp) {
                return true; // Brand-new IP for an established account.
            }
        }

        // Signal 2: recent failed attempts against this account from other IPs.
        $failedFromOtherIps = DB::table('login_attempts')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('success', false)
            ->where('ip_address', '!=', $ipAddress)
            ->where('attempted_at', '>=', $since)
            ->count();

        if ($failedFromOtherIps > 0) {
            return true;
        }

        // Signal 3: this IP recently failed logins for several accounts.
        $distinctEmailsFailedFromThisIp = DB::table('login_attempts')
            ->where('ip_address', $ipAddress)
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subHours(24)->toDateTimeString())
            ->distinct()
            ->count(DB::raw('LOWER(email)'));

        if ($distinctEmailsFailedFromThisIp >= 3) {
            return true;
        }

        return false;
    } catch (Throwable $error) {
        error_log('isSuspiciousLoginAttempt failed: ' . $error->getMessage());
        return false;
    }
}

/**
 * Validate a Google reCAPTCHA v2 token with the remote verification API.
 *
 * v2 responses carry no score — a successful siteverify means the visitor
 * actually solved the checkbox challenge, so `success === true` is the whole
 * check (v2 has no equivalent of the v3 score threshold).
 *
 * @param  string  $token      The reCAPTCHA v2 response token from the client.
 * @param  string  $secretKey  The reCAPTCHA v2 secret key.
 * @param  string  $remoteIp   The client IP (used by reCAPTCHA for anomaly detection).
 * @return bool                 true when the token is valid.
 */
function verifyRecaptchaToken(string $token, string $secretKey, string $remoteIp = ''): bool
{
    if ($token === '') {
        return false;
    }

    try {
        $payload = http_build_query([
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            error_log('[MOTASTE] reCAPTCHA verification request failed: HTTP ' . $httpCode);
            return false;
        }

        $result = json_decode($body, true);
        return is_array($result) && ($result['success'] ?? false) === true;
    } catch (Throwable $error) {
        error_log('[MOTASTE] reCAPTCHA verification error: ' . $error->getMessage());
        return false;
    }
}

/**
 * Append a structured event to the api_event_logs audit table.
 */
function logApiEvent(string $event, array $details = []): void
{
    ensureStaffEnhancementSchema();

    try {
        DB::table('api_event_logs')->insert([
            'event' => $event,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $error) {
        error_log('logApiEvent failed: ' . $error->getMessage());
    }
}

/**
 * Append a staff login/logout event to the account activity audit trail
 * (order_activity_logs, surfaced under Logs > Account).
 *
 * Shared by the login notification and by logout_staff.php so the two entries
 * cannot drift apart in action name, summary, or details shape.
 *
 * Deliberately never throws and never writes an unattributable row: auditing
 * must not be able to fail an auth response, and a blank actor or an unknown
 * event is a no-op.
 */
function recordStaffAccountActivity(
    string $event,
    string $role,
    string $email,
    ?string $occurredAt = null,
    ?string $userAgent = null
): void {
    $event = strtolower(trim($event));
    $role = trim($role);
    $email = strtolower(trim($email));

    if (!in_array($event, ['login', 'logout'], true) || $role === '' || $email === '') {
        return;
    }

    $eventAt = trim((string) $occurredAt);
    if ($eventAt === '') {
        $eventAt = now()->toDateTimeString();
    }

    $agent = trim((string) $userAgent);
    if ($agent === '') {
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    try {
        DB::table('order_activity_logs')->insert([
            'order_id' => null,
            'order_number' => null,
            'action' => $event === 'login' ? 'account_login' : 'account_logout',
            'actor_role' => $role,
            'actor_email' => $email,
            'summary' => ($role === 'Admin' ? 'Administrator' : $role) . ($event === 'login' ? ' logged in' : ' logged out'),
            'details' => json_encode([
                'event' => $event,
                'occurred_at' => $eventAt,
                'user_agent' => $agent,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $error) {
        error_log('recordStaffAccountActivity failed: ' . $error->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Order API rate limiting                                             */
/* ------------------------------------------------------------------ */

const ORDER_CREATE_MAX_PER_WINDOW = 15;   // order creations
const ORDER_CREATE_WINDOW_SECONDS = 600;  // per 10 minutes, per IP
const ORDER_STATUS_MAX_PER_WINDOW = 240;  // order status lookups
const ORDER_STATUS_WINDOW_SECONDS = 60;   // per 60 seconds, per IP

function resolveApiClientIp(): string
{
    // Reuse the device-auth helper's resolver when loaded (prefers REMOTE_ADDR
    // over the spoofable X-Forwarded-For header).
    if (function_exists('resolveClientIpAddress')) {
        return resolveClientIpAddress();
    }

    // Same logic as resolveClientIpAddress: never trust client-set headers.
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote !== '' && $remote !== '::1') {
        return $remote;
    }

    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $first = trim((string)$parts[0]);
        if ($first !== '') {
            return $first;
        }
    }

    return $remote;
}

/* ------------------------------------------------------------------ */
/* Admin account (dedicated `admins` table)                            */
/* ------------------------------------------------------------------ */

/**
 * Ensure the `admins` table exists for code paths that write to it directly.
 * Mirrors the on-demand schema convention used elsewhere; the migration is
 * still the primary owner of the schema.
 */
function ensureAdminsTable(): void
{
    try {
        if (!Schema::hasTable('admins')) {
            Schema::create('admins', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('full_name', 191)->nullable();
                $table->string('email', 191)->unique();
                $table->string('password_hash', 191)->nullable();
                $table->string('role', 100)->default('Admin');
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();
            });
        }
    } catch (Throwable $error) {
        error_log('ensureAdminsTable failed: ' . $error->getMessage());
    }
}

/**
 * Locate the Admin account row and the table it currently lives in.
 *
 * Prefers the dedicated `admins` table and falls back to a legacy `staff`
 * row with role = 'Admin' so a deploy that has not yet run the split
 * migration keeps working.
 *
 * @param  string  $email  Optional email to restrict the lookup to.
 * @return array{0: string, 1: object}|null  [table, row]
 */
function findAdminAccountRow(string $email = ''): ?array
{
    $email = strtolower(trim($email));

    try {
        if (Schema::hasTable('admins')) {
            $query = DB::table('admins');
            if ($email !== '') {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            }
            $row = $query->orderBy('id')->first();
            if ($row) {
                return ['admins', $row];
            }
        }

        // Legacy fallback: the Admin row still lives in `staff`.
        if (Schema::hasTable('staff') && Schema::hasColumn('staff', 'role')) {
            $query = DB::table('staff')->whereRaw('LOWER(role) = ?', ['admin']);
            if ($email !== '') {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            }
            $row = $query->orderBy('id')->first();
            if ($row) {
                return ['staff', $row];
            }
        }
    } catch (Throwable $error) {
        error_log('findAdminAccountRow failed: ' . $error->getMessage());
    }

    return null;
}

/**
 * Whether the given email belongs to the Admin account.
 */
function isAdminEmail(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    return findAdminAccountRow($email) !== null;
}

/**
 * The single Admin account's email, or '' when no admin exists.
 */
function getAdminEmailAddress(): string
{
    $found = findAdminAccountRow();
    if ($found === null) {
        return '';
    }

    return strtolower(trim((string)($found[1]->email ?? '')));
}

/**
 * Resolve an authentication account (staff OR admin) by email.
 *
 * Admin rows are returned with role = 'Admin' so the rest of the auth flow
 * behaves exactly as it did when the Admin lived in `staff`.
 */
function findStaffAuthAccount(string $email): ?object
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    try {
        if (Schema::hasTable('staff')) {
            $row = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($row) {
                return $row;
            }
        }

        $admin = findAdminAccountRow($email);
        if ($admin !== null) {
            $row = $admin[1];
            $row->role = 'Admin';
            // `position` only exists on staff; normalize for consumers.
            if (!isset($row->position)) {
                $row->position = null;
            }
            return $row;
        }
    } catch (Throwable $error) {
        error_log('findStaffAuthAccount failed: ' . $error->getMessage());
    }

    return null;
}

/**
 * The table that holds the account with this email ('staff' or 'admins'),
 * or null when neither has it.
 */
function staffAccountTableForEmail(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    try {
        if (Schema::hasTable('admins') && DB::table('admins')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'admins';
        }
        if (Schema::hasTable('staff') && DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'staff';
        }
    } catch (Throwable $error) {
        error_log('staffAccountTableForEmail failed: ' . $error->getMessage());
    }

    return null;
}

/**
 * Returns the authenticated staff array when the account is an Admin, or null.
 * Guards account-management endpoints so a Cashier cannot promote themselves
 * or modify other staff accounts.
 */
function requireAdminAuth(): ?array
{
    $staff = requireStaffAuth();
    if ($staff && strtolower(trim((string)($staff['role'] ?? ''))) === 'admin') {
        return $staff;
    }

    return null;
}

/**
 * Returns the authenticated staff array for Admin or Cashier accounts, or
 * null. Guards order-management endpoints (create/complete/cancel/refund).
 */
function requireOrderManagerAuth(): ?array
{
    $staff = requireStaffAuth();
    if (!$staff) {
        return null;
    }
    $role = strtolower(trim((string)($staff['role'] ?? '')));
    return in_array($role, ['admin', 'cashier'], true) ? $staff : null;
}

/**
 * Returns the authenticated staff array for Admin or Inventory Manager
 * accounts, or null. Guards inventory/catalog endpoints.
 */
function requireInventoryAuth(): ?array
{
    $staff = requireStaffAuth();
    if (!$staff) {
        return null;
    }
    $role = strtolower(trim((string)($staff['role'] ?? '')));
    return in_array($role, ['admin', 'inventory manager'], true) ? $staff : null;
}

/**
 * Ensure the order_request_log table used to rate-limit public order APIs.
 */
function ensureOrderRequestLogTable(): void
{
    try {
        if (!Schema::hasTable('order_request_log')) {
            Schema::create('order_request_log', function (Blueprint $table) {
                $table->id();
                $table->string('ip_address', 45)->nullable();
                $table->string('endpoint', 64)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['ip_address', 'endpoint', 'created_at'], 'order_request_log_ip_endpoint_idx');
            });
        }
    } catch (Throwable $error) {
        // Rate limiting must never block a request; the migration applies on deploy.
        error_log('order_request_log table check failed: ' . $error->getMessage());
    }
}

/**
 * Record a public order API request (create/lookup) for rate limiting.
 */
function recordOrderApiRequest(string $endpoint): void
{
    ensureOrderRequestLogTable();

    try {
        DB::table('order_request_log')->insert([
            'ip_address' => resolveApiClientIp(),
            'endpoint' => $endpoint,
            'created_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $error) {
        error_log('order_request_log insert failed: ' . $error->getMessage());
    }
}

/**
 * True when this IP has exceeded the per-window request budget for an endpoint.
 */
function isOrderApiRateLimited(string $endpoint, int $maxRequests, int $windowSeconds): bool
{
    ensureOrderRequestLogTable();

    try {
        $since = now()->subSeconds($windowSeconds);
        $count = DB::table('order_request_log')
            ->where('endpoint', $endpoint)
            ->where('ip_address', resolveApiClientIp())
            ->where('created_at', '>=', $since->toDateTimeString())
            ->count();

        return $count >= $maxRequests;
    } catch (Throwable $error) {
        error_log('order_request_log rate check failed: ' . $error->getMessage());
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* Staff session tokens                                               */
/* ------------------------------------------------------------------ */

// NOTE: the TTL itself is the env-tunable STAFF_SESSION_TOKEN_TTL_DAYS
// (resolved by staffSessionTokenTtlDays()); only the per-account session cap
// stays hardcoded.
const STAFF_SESSION_TOKEN_MAX_PER_ACCOUNT = 5;

/**
 * Name of the HttpOnly cookie that carries the staff session token.
 *
 * The token used to be returned in the login JSON and persisted by the client
 * in localStorage/sessionStorage, where any XSS could read it and replay it
 * from another machine. It is now delivered as an HttpOnly cookie: page script
 * cannot read it, and the browser attaches it to same-origin requests
 * automatically.
 */
const STAFF_SESSION_COOKIE_NAME = 'motaste_staff_session';

/**
 * Write (or clear) the HttpOnly staff session-token cookie.
 *
 * $remember = true  -> persistent cookie (survives browser restarts)
 * $remember = false -> session cookie (dies with the browser)
 *
 * Pass null/'' as $token to clear the cookie.
 */
function setStaffSessionTokenCookie(?string $token, bool $remember = false): void
{
    if (headers_sent()) {
        return;
    }

    // Match the session cookie's secure policy: only mandate Secure on HTTPS.
    // A hardcoded true would break cookie round-trips on HTTP deployments
    // (e.g. local dev or a non-TLS staging URL) where the PHP session cookie
    // still works because it uses the same conditional check.
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $name = STAFF_SESSION_COOKIE_NAME;

    if ($token === null || $token === '') {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return;
    }

    $options = [
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ];

    if ($remember) {
        $options['expires'] = time() + staffSessionTokenTtlDays() * 86400;
    }

    setcookie($name, $token, $options);
}

/**
 * Read the staff session token from the HttpOnly cookie, or null.
 */
function readStaffSessionTokenCookie(): ?string
{
    $raw = $_COOKIE[STAFF_SESSION_COOKIE_NAME] ?? '';
    $token = trim((string)$raw);

    return $token !== '' ? $token : null;
}

/**
 * Resolve the staff session token for a request. The HttpOnly cookie is the
 * primary source; a request-body token is still accepted as a fallback for
 * clients built before the cookie switch.
 */
function resolveStaffSessionRequestToken(?string $bodyToken = null): ?string
{
    // Primary: HttpOnly cookie — not reachable by page script.
    $cookieToken = readStaffSessionTokenCookie();
    if ($cookieToken !== null) {
        return $cookieToken;
    }

    // Legacy fallback: request body. Kept only for clients built before the
    // cookie switch. Do NOT promote this path — it re-exposes the token to
    // any XSS that can read the request payload the client sends.
    $bodyToken = trim((string)$bodyToken);

    return $bodyToken !== '' ? $bodyToken : null;
}

function ensureStaffSessionTokenTable(): void
{
    // Table is created by ensureStaffEnhancementSchema(); this is a safe no-op
    // fallback for code paths that need it directly.
    try {
        if (!Schema::hasTable('staff_session_tokens')) {
            Schema::create('staff_session_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('role', 100)->nullable();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index('email', 'staff_session_tokens_email_idx');
            });
        }
    } catch (Throwable $error) {
        error_log('ensureStaffSessionTokenTable failed: ' . $error->getMessage());
    }
}

/**
 * Issue a new opaque bearer token for a staff account. Only the SHA-256 hash
 * is stored; the plaintext token is returned exactly once for the client to
 * keep in browser storage (replacing plaintext passwords).
 */
function issueStaffSessionToken(string $email, string $role): string
{
    ensureStaffSessionTokenTable();
    $email = strtolower(trim($email));
    $token = bin2hex(random_bytes(32));

    try {
        // Expire old tokens and cap the number of live sessions per account.
        DB::table('staff_session_tokens')
            ->where('email', $email)
            ->where('expires_at', '<', now()->toDateTimeString())
            ->delete();

        $liveCount = (int)DB::table('staff_session_tokens')->where('email', $email)->count();
        if ($liveCount >= STAFF_SESSION_TOKEN_MAX_PER_ACCOUNT) {
            $oldest = DB::table('staff_session_tokens')
                ->where('email', $email)
                ->orderBy('id', 'asc')
                ->limit($liveCount - STAFF_SESSION_TOKEN_MAX_PER_ACCOUNT + 1)
                ->get(['id']);
            foreach ($oldest as $row) {
                DB::table('staff_session_tokens')->where('id', $row->id)->delete();
            }
        }

        DB::table('staff_session_tokens')->insert([
            'email' => $email,
            'role' => trim($role),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(staffSessionTokenTtlDays())->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $error) {
        error_log('issueStaffSessionToken failed: ' . $error->getMessage());
    }

    return $token;
}

/**
 * Resolve a session token to its account identity, or null when invalid/expired.
 */
function resolveStaffSessionToken(?string $token): ?array
{
    if ($token === null || trim($token) === '') {
        return null;
    }
    ensureStaffSessionTokenTable();

    try {
        $row = DB::table('staff_session_tokens')
            ->where('token_hash', hash('sha256', trim($token)))
            ->first();
        if (!$row) {
            return null;
        }
        if (now()->greaterThan($row->expires_at)) {
            DB::table('staff_session_tokens')->where('id', $row->id)->delete();
            return null;
        }
        return [
            'email' => strtolower(trim((string)$row->email)),
            'role' => trim((string)($row->role ?? '')),
        ];
    } catch (Throwable $error) {
        error_log('resolveStaffSessionToken failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Revoke a single session token (logout).
 */
function revokeStaffSessionToken(?string $token): void
{
    if ($token === null || trim($token) === '') {
        return;
    }
    ensureStaffSessionTokenTable();

    try {
        DB::table('staff_session_tokens')
            ->where('token_hash', hash('sha256', trim($token)))
            ->delete();
    } catch (Throwable $error) {
        error_log('revokeStaffSessionToken failed: ' . $error->getMessage());
    }
}

/**
 * Revoke every live session for an account (used after a password/email change).
 */
function revokeAllStaffSessionTokens(string $email): void
{
    ensureStaffSessionTokenTable();

    try {
        DB::table('staff_session_tokens')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->delete();
    } catch (Throwable $error) {
        error_log('revokeAllStaffSessionTokens failed: ' . $error->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Low-stock email alert                                               */
/* ------------------------------------------------------------------ */

const LOW_STOCK_THRESHOLD = 20; // units at or below which an item is low stock

/**
 * Send a low-stock alert email to the admin address when inventory items drop
 * to (or below) 20 units. Runs at most once per item per 6-hour window
 * (tracked in api_event_logs) to avoid email spam.
 */
function notifyLowStockAlerts(): void
{
    ensureStaffEnhancementSchema();

    try {
        $lowItems = DB::table('inventory_items')
            ->where('stock', '<=', LOW_STOCK_THRESHOLD)
            ->orderBy('stock', 'asc')
            ->get(['name', 'stock', 'updated_at'])
            ->all();

        if (!$lowItems) {
            return;
        }

        $freshItems = [];
        $windowStart = now()->subHours(6);
        foreach ($lowItems as $item) {
            $key = 'low_stock_alert_' . md5(strtolower(trim((string)($item->name ?? ''))));
            $alreadySent = DB::table('api_event_logs')
                ->where('event', $key)
                ->where('created_at', '>=', $windowStart->toDateTimeString())
                ->exists();
            if (!$alreadySent) {
                $freshItems[] = $item;
            }
        }

        if (!$freshItems) {
            return;
        }

        if (!function_exists('sendSystemEmail')) {
            require_once __DIR__ . '/_email_auth_helpers.php';
        }

        $lines = array_map(function ($item) {
            return '- ' . $item->name . ': ' . (int)$item->stock . ' left';
        }, $freshItems);

        $adminEmail = getAdminEmailAddress();
        if ($adminEmail === '') {
            return;
        }

        $result = sendSystemEmail(
            $adminEmail,
            'MOTASTE Low Stock Alert',
            "The following items are at or below " . LOW_STOCK_THRESHOLD . " units in stock:\n\n" . implode("\n", $lines) . "\n\nPlease restock soon.\n\nThis message was sent automatically by the MOTASTE ordering system."
        );

        foreach ($freshItems as $item) {
            logApiEvent('low_stock_alert_' . md5(strtolower(trim((string)($item->name ?? '')))), [
                'name' => $item->name,
                'stock' => (int)$item->stock,
                'threshold' => LOW_STOCK_THRESHOLD,
                'email_delivered' => ($result['success'] ?? false),
            ]);
        }
    } catch (Throwable $error) {
        error_log('notifyLowStockAlerts failed: ' . $error->getMessage());
    }
}
