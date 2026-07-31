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
    DB::statement("CREATE TABLE IF NOT EXISTS orders (
        id BIGSERIAL PRIMARY KEY,
        order_number VARCHAR(191) NOT NULL,
        order_date TIMESTAMP NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        payment_status VARCHAR(50) NOT NULL DEFAULT 'unpaid',
        payment_method VARCHAR(50) NULL,
        order_type VARCHAR(50) NULL,
        subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,
        total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    DB::statement("CREATE TABLE IF NOT EXISTS order_items (
        id BIGSERIAL PRIMARY KEY,
        order_id BIGINT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
        line_total NUMERIC(12,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        components TEXT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    $orders = DB::table('orders')
        ->where('status', 'completed')
        ->orderByDesc('order_date')
        ->limit(500)
        ->get();

    $result = $orders->map(function ($order) {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->get()
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
            'subtotal' => (float)($order->subtotal ?? 0),
            'total_amount' => (float)($order->total_amount ?? 0),
            'items' => $items,
        ];
    })->values()->all();

    echo json_encode(['success' => true, 'orders' => $result]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load completed orders', 'details' => $error->getMessage()]);
}
