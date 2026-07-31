<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$orderNumber = trim((string)($input['orderNumber'] ?? ''));
if ($orderNumber === '') {
    $orderNumber = (string)time();
}

$items = is_array($input['items'] ?? null) ? $input['items'] : [];
$paymentMethod = trim((string)($input['paymentMethod'] ?? 'Cash'));
$orderType = trim((string)($input['orderType'] ?? 'Dine In'));

$subtotal = 0;
foreach ($items as $it) {
    $subtotal += (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0);
}
$total = $subtotal;

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

    $orderId = null;
    $insertedItems = 0;

    DB::transaction(function () use (&$orderId, &$insertedItems, $orderNumber, $paymentMethod, $orderType, $subtotal, $total, $items) {
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => $orderNumber,
            'order_date' => $now,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => $paymentMethod,
            'order_type' => $orderType,
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($items as $it) {
            $itemName = trim((string)($it['name'] ?? 'Menu item'));
            $price = (float)($it['price'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $lineTotal = $price * $qty;
            $components = is_array($it['components'] ?? null) ? array_values($it['components']) : null;

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'quantity' => $qty,
                'unit_price' => $price,
                'line_total' => $lineTotal,
                'notes' => $itemName,
                'components' => $components !== null ? json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $insertedItems++;
        }
    });

    echo json_encode(['success' => true, 'orderId' => $orderId, 'insertedItems' => $insertedItems]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert order failed', 'details' => $error->getMessage()]);
}
