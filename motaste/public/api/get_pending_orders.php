<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}


use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

require_once __DIR__ . '/_helpers.php';

try {
    // Lightweight count-only mode for the overview KPI. The metrics refresh
    // only needs the pending count, so avoid the full orders+items payload
    // (and the auto-expiry UPDATE below, which the 10s poller still runs).
    if ((int)($_GET['count'] ?? 0) === 1) {
        $pendingCount = DB::table('orders')->where('status', 'pending')->count();
        echo json_encode(['success' => true, 'count' => $pendingCount]);
        exit;
    }

    ensureOrderPrepTimerColumns();

    // Only pending orders that have not started preparation are auto-expired.
    // Accepted orders (prep timer running) must stay visible until completed.
    DB::table('orders')
        ->where('status', 'pending')
        ->whereNull('prep_started_at')
        ->whereRaw("COALESCE(updated_at, order_date) <= NOW() - INTERVAL '10 minutes'")
        ->update([
            'status' => 'expired',
            'updated_at' => now(),
        ]);

    $orders = DB::table('orders')
        ->where('status', 'pending')
        ->orderByDesc('order_date')
        ->limit(100)
        ->get();

    // Batch-fetch all order items in one query (avoids N+1).
    $orderIds = $orders->pluck('id')->all();
    $allItems = [];
    if (!empty($orderIds)) {
        $rawItems = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->get();
        foreach ($rawItems as $it) {
            $oid = (int)$it->order_id;
            $components = null;
            try {
                $components = json_decode((string)($it->components ?? ''), true);
            } catch (Throwable $e) {
                $components = null;
            }
            $allItems[$oid][] = [
                'id' => (int)$it->id,
                'order_id' => $oid,
                'name' => $it->notes ?: 'Menu item',
                'notes' => $it->notes,
                'price' => (float)($it->unit_price ?? 0),
                'unit_price' => (float)($it->unit_price ?? 0),
                'quantity' => (int)($it->quantity ?? 0),
                'components' => is_array($components) ? $components : [],
            ];
        }
    }

    $result = $orders->map(function ($order) use ($allItems) {
        $items = $allItems[(int)$order->id] ?? [];

        return [
            'id' => (int)$order->id,
            'order_number' => $order->order_number,
            'order_date' => $order->order_date,
            'order_date_iso' => $order->order_date ? Carbon::parse($order->order_date)->toIso8601String() : null,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'order_type' => $order->order_type,
            'customer_name' => $order->customer_name ?? null,
            'delivery_address' => $order->delivery_address ?? null,
            'prep_minutes' => isset($order->prep_minutes) ? (int)$order->prep_minutes : null,
            'prep_started_at' => $order->prep_started_at ?? null,
            'prep_started_at_iso' => $order->prep_started_at ? Carbon::parse($order->prep_started_at)->toIso8601String() : null,
            'subtotal' => (float)($order->subtotal ?? 0),
            'total_amount' => (float)($order->total_amount ?? 0),
            'items' => $items,
        ];
    })->values()->all();

    echo json_encode(['success' => true, 'orders' => $result]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Load pending orders failed']);
}
