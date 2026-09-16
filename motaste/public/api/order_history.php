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

/**
 * Validate an ISO/parseable date param, normalized to 'Y-m-d H:i:s' (UTC,
 * matching how order_date is stored). Returns null when absent/unparsable.
 */
function orderHistoryDateParam($value) {
    if ($value === null || $value === '') return null;
    try {
        return Carbon::parse($value)->toDateTimeString();
    } catch (Throwable $e) {
        return null;
    }
}

try {
    $fromParam = isset($_GET['from']) && $_GET['from'] !== '' ? trim($_GET['from']) : null;
    $toParam = isset($_GET['to']) && $_GET['to'] !== '' ? trim($_GET['to']) : null;

    // from/to are required: the client always selects a concrete day.
    if ($fromParam === null || $toParam === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'from and to date filters are required']);
        exit;
    }
    $from = orderHistoryDateParam($fromParam);
    $to = orderHistoryDateParam($toParam);
    if ($from === null || $to === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'from and to must be valid dates']);
        exit;
    }
    if (strtotime($from) >= strtotime($to)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'from must be before to']);
        exit;
    }

    // Order history is a 3-month track record: never serve anything older.
    $cutoff = now()->subMonths(3);
    if ($from < $cutoff) {
        $from = $cutoff;
    }

    // Optional status filter (comma-separated allowlist). Defaults to completed
    // orders; the Overview "today" feed passes the full set so every order of
    // the day stays visible regardless of its final state.
    $statusesParam = isset($_GET['statuses']) && $_GET['statuses'] !== '' ? trim($_GET['statuses']) : 'completed';
    $allowedStatuses = ['pending', 'completed', 'expired', 'cancelled', 'refunded'];
    $statuses = array_values(array_unique(array_filter(
        array_map(static fn ($value) => trim($value), explode(',', $statusesParam)),
        static fn ($value) => in_array($value, $allowedStatuses, true)
    )));
    if ($statuses === []) {
        $statuses = ['completed'];
    }

    $query = DB::table('orders')
        ->whereIn('status', $statuses)
        ->where('order_date', '>=', $from)
        ->where('order_date', '<', $to)
        ->limit(5000);

    $orders = $query
        ->orderByDesc('order_date')
        ->get();

    // Batch-fetch all order items in one query (avoids N+1).
    $orderIds = $orders->pluck('id')->all();
    $allItems = [];
    if (!empty($orderIds)) {
        $rawItems = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->get();
        foreach ($rawItems as $item) {
            $oid = (int)($item->order_id ?? 0);
            $components = null;
            try {
                $components = json_decode((string)($item->components ?? ''), true);
            } catch (Throwable $e) {
                $components = null;
            }
            $allItems[$oid][] = [
                'id' => (int)($item->id ?? 0),
                'order_id' => $oid,
                'name' => $item->notes ?: 'Menu item',
                'notes' => $item->notes,
                'price' => (float)($item->unit_price ?? 0),
                'unit_price' => (float)($item->unit_price ?? 0),
                'quantity' => (int)($item->quantity ?? 0),
                'line_total' => (float)($item->line_total ?? 0),
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
            'subtotal' => (float)($order->subtotal ?? 0),
            'total_amount' => (float)($order->total_amount ?? 0),
            'total' => (float)($order->total_amount ?? $order->total ?? 0),
            'payment_received' => $order->payment_received !== null ? (float)$order->payment_received : null,
            'change_due' => $order->change_due !== null ? (float)$order->change_due : null,
            'items' => $items,
        ];
    })->values()->all();

    echo json_encode([
        'success' => true,
        'orders' => $result,
        'cutoff' => $cutoff->toIso8601String(),
        'from' => $from,
        'to' => $to,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load order history']);
}