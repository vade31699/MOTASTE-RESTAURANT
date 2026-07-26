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
$orderId = isset($input['orderId']) ? (int)$input['orderId'] : 0;

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderId is required']);
    exit;
}

try {
    $updated = DB::table('orders')
        ->where('id', $orderId)
        ->update([
            'status' => 'completed',
            'updated_at' => now(),
        ]);

    if (!$updated) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $orderNumber = DB::table('orders')->where('id', $orderId)->value('order_number');

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'orderNumber' => $orderNumber,
        'status' => 'completed',
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to mark order complete', 'details' => $error->getMessage()]);
}
