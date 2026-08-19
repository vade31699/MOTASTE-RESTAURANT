<?php
/**
 * Fresh Start — clears all transactional data while preserving accounts
 * and inventory. Admin-only.
 *
 * Clears: highlights, reviews, completed/pending orders, order items,
 *         order events, order activity logs, review activity logs,
 *         review daily blocks, custom menu snapshots, pending orders.
 *
 * Preserves: staff, inventory_items, trusted_devices, session tokens,
 *            login history, login attempts, staff_invite_tokens.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$deleted = [];
$errors = [];

$tablesToTruncate = [
    'highlights_snapshots',
    'customer_reviews',
    'review_daily_blocks',
    'review_activity_logs',
    'order_activity_logs',
    'order_events',
    'data_retention_batches',
];

try {
    DB::transaction(function () use (&$deleted, &$errors) {

        // 1. Truncate simple tables
        foreach ($tablesToTruncate as $table) {
            try {
                if (Schema::hasTable($table)) {
                    $count = DB::table($table)->count();
                    DB::table($table)->truncate();
                    $deleted[$table] = $count;
                }
            } catch (Throwable $e) {
                $errors[$table] = $e->getMessage();
            }
        }

        // 2. Clear orders + order_items (completed and pending)
        try {
            if (Schema::hasTable('order_items')) {
                $itemCount = DB::table('order_items')->count();
                DB::table('order_items')->truncate();
                $deleted['order_items'] = $itemCount;
            }
        } catch (Throwable $e) {
            $errors['order_items'] = $e->getMessage();
        }

        try {
            if (Schema::hasTable('orders')) {
                $orderCount = DB::table('orders')->count();
                DB::table('orders')->truncate();
                $deleted['orders'] = $orderCount;
            }
        } catch (Throwable $e) {
            $errors['orders'] = $e->getMessage();
        }

        // 3. Clear custom menu snapshots (customer menu state)
        try {
            if (Schema::hasTable('custom_menu_snapshots')) {
                $snapCount = DB::table('custom_menu_snapshots')->count();
                DB::table('custom_menu_snapshots')->truncate();
                $deleted['custom_menu_snapshots'] = $snapCount;
            }
        } catch (Throwable $e) {
            $errors['custom_menu_snapshots'] = $e->getMessage();
        }
    });

    // 4. Clear server-side caches
    try {
        \Illuminate\Support\Facades\Cache::forget('inventory_staff_v1');
        \Illuminate\Support\Facades\Cache::forget('inventory_public_v1');
    } catch (Throwable $e) {
        // best-effort
    }

    echo json_encode([
        'success' => true,
        'deleted' => $deleted,
        'errors' => $errors,
        'message' => 'Fresh start complete. Accounts and inventory preserved.',
    ]);

} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fresh start failed: ' . $error->getMessage(),
        'deleted' => $deleted,
        'errors' => $errors,
    ]);
}
