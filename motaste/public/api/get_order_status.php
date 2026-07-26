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
$orderNumbers = $input['orderNumbers'] ?? [];

if (!is_array($orderNumbers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderNumbers must be an array']);
    exit;
}

$normalizedOrderNumbers = array_values(array_filter(array_map(static function ($value) {
    return trim((string)$value);
}, $orderNumbers), static function ($value) {
    return $value !== '';
}));

if (empty($normalizedOrderNumbers)) {
    echo json_encode(['success' => true, 'orders' => []]);
    exit;
}

try {
    $orders = DB::table('orders')
        ->whereIn('order_number', $normalizedOrderNumbers)
        ->orderByDesc('updated_at')
        ->get(['id', 'order_number', 'status', 'updated_at'])
        ->map(static function ($order) {
            return [
                'id' => (int)$order->id,
                'order_number' => (string)$order->order_number,
                'status' => (string)$order->status,
                'updated_at' => $order->updated_at,
            ];
        })
        ->values()
        ->all();

    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to fetch order status', 'details' => $error->getMessage()]);
}
