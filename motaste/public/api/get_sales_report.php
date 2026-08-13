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

try {
    $from = isset($_GET['from']) ? (string)$_GET['from'] : '';
    $to = isset($_GET['to']) ? (string)$_GET['to'] : '';
    $tzOffset = isset($_GET['tz']) ? (int)$_GET['tz'] : 0;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'from and to must be in YYYY-MM-DD format']);
        exit;
    }

    // The report dates are chosen in the viewer's local timezone. Convert the
    // selected local day into UTC instants so orders (stored in UTC via now())
    // are attributed to the correct calendar day for the user.
    if ($tzOffset < -720 || $tzOffset > 840) {
        $tzOffset = 0;
    }
    $tzName = sprintf('%+03d:%02d', intdiv($tzOffset, 60), abs($tzOffset % 60));
    $tz = new DateTimeZone($tzName);

    $fromDate = Carbon::parse($from . ' 00:00:00', $tz)->setTimezone('UTC');
    $toDate = Carbon::parse($to . ' 23:59:59', $tz)->setTimezone('UTC');

    $orders = DB::table('orders')
        ->where('status', 'completed')
        ->whereBetween('order_date', [$fromDate, $toDate])
        ->orderBy('order_date')
        ->get();

    // Load every line item in a single query instead of one query per order.
    $itemsByOrder = DB::table('order_items')
        ->whereIn('order_id', $orders->pluck('id')->all())
        ->get()
        ->groupBy('order_id');

    $result = $orders->map(function ($order) use ($itemsByOrder) {
        $items = ($itemsByOrder->get($order->id) ?: collect())
            ->map(function ($item) {
                $components = null;
                try {
                    $components = json_decode((string)($item->components ?? ''), true);
                } catch (Throwable $e) {
                    $components = null;
                }

                return [
                    'id' => (int)($item->id ?? 0),
                    'order_id' => (int)($item->order_id ?? 0),
                    'name' => $item->notes ?: 'Menu item',
                    'notes' => $item->notes,
                    'price' => (float)($item->unit_price ?? 0),
                    'unit_price' => (float)($item->unit_price ?? 0),
                    'quantity' => (int)($item->quantity ?? 0),
                    'line_total' => (float)($item->line_total ?? 0),
                    'components' => is_array($components) ? $components : [],
                ];
            })
            ->values()
            ->all();

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
            'items' => $items,
        ];
    })->values()->all();

    echo json_encode([
        'success' => true,
        'from' => $from,
        'to' => $to,
        'count' => count($result),
        'orders' => $result,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load sales report']);
}
