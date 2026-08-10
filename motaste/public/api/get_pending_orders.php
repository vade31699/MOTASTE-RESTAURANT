<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

try {
    
    
    DB::table('orders')
        ->where('status', 'pending')
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

    $result = $orders->map(function ($order) {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->get()
            ->map(function ($it) {
                $components = null;
                try {
                    $components = json_decode((string)($it->components ?? ''), true);
                } catch (Throwable $e) {
                    $components = null;
                }

                return [
                    'id' => (int)$it->id,
                    'order_id' => (int)$it->order_id,
                    'name' => $it->notes ?: 'Menu item',
                    'notes' => $it->notes,
                    'price' => (float)($it->unit_price ?? 0),
                    'unit_price' => (float)($it->unit_price ?? 0),
                    'quantity' => (int)($it->quantity ?? 0),
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
            'items' => $items,
        ];
    })->values()->all();

    echo json_encode(['success' => true, 'orders' => $result]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Load pending orders failed', 'details' => $error->getMessage()]);
}
