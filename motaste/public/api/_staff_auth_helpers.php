<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        if (!Schema::hasTable('loyalty_accounts')) {
            Schema::create('loyalty_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('phone', 40)->unique();
                $table->string('name', 191)->nullable();
                $table->integer('points')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('loyalty_transactions')) {
            Schema::create('loyalty_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('phone', 40)->index();
                $table->integer('points_delta')->default(0);
                $table->string('reason', 100)->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('order_number', 191)->nullable();
                $table->timestamps();

                $table->index('phone', 'loyalty_transactions_phone_idx');
                $table->index('order_id', 'loyalty_transactions_order_id_idx');
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
        if (!Schema::hasColumn('orders', 'loyalty_points_redeemed')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->integer('loyalty_points_redeemed')->default(0)->after('discount');
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
        return $_SESSION['staff'];
    }

    return null;
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
/* Loyalty                                                             */
/* ------------------------------------------------------------------ */

const LOYALTY_POINTS_PER_PESO = 1;          // 1 point per full peso spent
const LOYALTY_REDEMPTION_POINTS = 100;      // points needed per redemption
const LOYALTY_REDEMPTION_VALUE = 50;        // peso value per redemption

function getLoyaltyAccount(string $phone): ?object
{
    ensureStaffEnhancementSchema();

    return DB::table('loyalty_accounts')
        ->whereRaw('phone = ?', [trim($phone)])
        ->first();
}

function ensureLoyaltyAccount(string $phone, ?string $name = null): object
{
    ensureStaffEnhancementSchema();

    $phone = trim($phone);
    $existing = getLoyaltyAccount($phone);
    if ($existing) {
        if ($name !== null && trim($name) !== '') {
            DB::table('loyalty_accounts')->where('id', $existing->id)->update([
                'name' => trim($name),
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
        return $existing;
    }

    $id = DB::table('loyalty_accounts')->insertGetId([
        'phone' => $phone,
        'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
        'points' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return (object)['id' => $id, 'phone' => $phone, 'name' => $name, 'points' => 0];
}

/**
 * Award points for a completed order. Called by mark_order_complete.php.
 */
function awardLoyaltyPoints(int $orderId, string $orderNumber, float $total, ?string $phone, ?string $name = null): ?int
{
    if ($phone === null || trim($phone) === '') {
        return null;
    }

    ensureStaffEnhancementSchema();
    $phone = trim($phone);

    try {
        $account = ensureLoyaltyAccount($phone, $name);
        $points = max(1, (int)floor($total) * LOYALTY_POINTS_PER_PESO);

        DB::table('loyalty_accounts')->where('id', $account->id)->increment('points', $points);
        DB::table('loyalty_transactions')->insert([
            'phone' => $phone,
            'points_delta' => $points,
            'reason' => 'order_completed',
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        logApiEvent('loyalty_points_awarded', [
            'phone' => $phone,
            'points' => $points,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
        ]);

        return $points;
    } catch (Throwable $error) {
        error_log('awardLoyaltyPoints failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Redeem points for a walk-in order discount. Returns the discount amount or 0.
 */
function redeemLoyaltyPoints(string $phone, int $redeemBlocks, int $orderId, string $orderNumber): float
{
    if ($redeemBlocks <= 0) {
        return 0.0;
    }

    ensureStaffEnhancementSchema();
    $phone = trim($phone);

    try {
        $account = getLoyaltyAccount($phone);
        if (!$account) {
            return 0.0;
        }

        $required = $redeemBlocks * LOYALTY_REDEMPTION_POINTS;
        if ((int)($account->points ?? 0) < $required) {
            return 0.0;
        }

        $discount = $redeemBlocks * LOYALTY_REDEMPTION_VALUE;

        DB::table('loyalty_accounts')->where('id', $account->id)->decrement('points', $required);
        DB::table('loyalty_transactions')->insert([
            'phone' => $phone,
            'points_delta' => -$required,
            'reason' => 'redeemed_for_discount',
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        logApiEvent('loyalty_points_redeemed', [
            'phone' => $phone,
            'points' => $required,
            'discount' => $discount,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
        ]);

        return $discount;
    } catch (Throwable $error) {
        error_log('redeemLoyaltyPoints failed: ' . $error->getMessage());
        return 0.0;
    }
}

/* ------------------------------------------------------------------ */
/* Low-stock email alert                                               */
/* ------------------------------------------------------------------ */

/**
 * Send a low-stock/reorder alert email to the admin address when inventory
 * items drop to (or below) their reorder level. Runs at most once per item
 * per 6-hour window (tracked in api_event_logs) to avoid email spam.
 */
function notifyLowStockAlerts(): void
{
    ensureStaffEnhancementSchema();

    try {
        $lowItems = DB::table('inventory_items')
            ->where('reorder_level', '>', 0)
            ->whereColumn('stock', '<=', 'reorder_level')
            ->orderBy('stock', 'asc')
            ->get(['name', 'stock', 'reorder_level', 'updated_at'])
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
            return '- ' . $item->name . ': ' . (int)$item->stock . ' left (reorder at ' . (int)$item->reorder_level . ')';
        }, $freshItems);

        $adminEmail = (string)DB::table('staff')->where('role', 'Admin')->value('email');
        if ($adminEmail === '') {
            return;
        }

        $result = sendSystemEmail(
            $adminEmail,
            'MOTASTE Low Stock Alert',
            "The following items are at or below their reorder level:\n\n" . implode("\n", $lines) . "\n\nPlease restock soon.\n\nThis message was sent automatically by the MOTASTE ordering system."
        );

        foreach ($freshItems as $item) {
            logApiEvent('low_stock_alert_' . md5(strtolower(trim((string)($item->name ?? '')))), [
                'name' => $item->name,
                'stock' => (int)$item->stock,
                'reorder_level' => (int)$item->reorder_level,
                'email_delivered' => ($result['success'] ?? false),
            ]);
        }
    } catch (Throwable $error) {
        error_log('notifyLowStockAlerts failed: ' . $error->getMessage());
    }
}
