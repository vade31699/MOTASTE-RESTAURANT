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

const STAFF_SESSION_LIFETIME_SECONDS = 30 * 24 * 60 * 60; // 30 days
const STAFF_LOGIN_MAX_ATTEMPTS = 6;
const STAFF_LOGIN_LOCKOUT_MINUTES = 15;

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
        'lifetime' => STAFF_SESSION_LIFETIME_SECONDS,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Returns the authenticated staff array (role/email/name) or null.
 */
function requireStaffAuth(): ?array
{
    ensureStaffAuthSession();

    if (!empty($_SESSION['staff']) && is_array($_SESSION['staff'])) {
        logStaffApiRequest((string)($_SERVER['REQUEST_URI'] ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api'))));
        touchStaffLastActive((string)($_SESSION['staff']['email'] ?? ''));
        return $_SESSION['staff'];
    }

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
        DB::table('staff')
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
        $rows = DB::table('staff')
            ->where('last_active_at', '>=', now()->subMinutes(5)->toDateTimeString())
            ->orderByDesc('last_active_at')
            ->get(['email', 'full_name', 'role', 'last_active_at'])
            ->all();

        $online = [];
        foreach ($rows as $row) {
            $online[] = [
                'email' => (string)($row->email ?? ''),
                'name' => trim((string)($row->full_name ?? '')) ?: 'Staff',
                'role' => trim((string)($row->role ?? '')) ?: 'Staff',
                'last_active_at' => (string)($row->last_active_at ?? ''),
            ];
        }
        return $online;
    } catch (Throwable $error) {
        error_log('getOnlineStaffAccounts failed: ' . $error->getMessage());
        return [];
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

    $since = now()->subMinutes(STAFF_LOGIN_LOCKOUT_MINUTES);
    $count = DB::table('login_attempts')
        ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
        ->where('success', false)
        ->where('attempted_at', '>=', $since->toDateTimeString())
        ->count();

    return $count >= STAFF_LOGIN_MAX_ATTEMPTS;
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
}

function clearLoginAttempts(string $email): void
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

const STAFF_SESSION_TOKEN_TTL_DAYS = 30;
const STAFF_SESSION_TOKEN_MAX_PER_ACCOUNT = 5;

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
            'expires_at' => now()->addDays(STAFF_SESSION_TOKEN_TTL_DAYS)->toDateTimeString(),
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

        $adminEmail = (string)DB::table('staff')->where('role', 'Admin')->value('email');
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
