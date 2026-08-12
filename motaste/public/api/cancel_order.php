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
require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();

use Illuminate\Support\Facades\DB;

$input = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['orderId']) ? (int)$input['orderId'] : 0;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderId is required']);
    exit;
}

try {
    $result = DB::transaction(function () use ($orderId) {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if (!$order) {
            return ['success' => false, 'status' => 404, 'error' => 'Order not found'];
        }

        $status = strtolower((string)($order->status ?? ''));
        if ($status === 'completed' || $status === 'expired' || $status === 'cancelled' || $status === 'refunded') {
            return [
                'success' => false,
                'status' => 409,
                'error' => 'This order can no longer be cancelled because it is already ' . $status . '.',
            ];
        }

        // Restore inventory stock for every line item.
        $orderItems = DB::table('order_items')
            ->where('order_id', $orderId)
            ->get(['notes', 'quantity']);

        $inventoryMap = [];
        foreach (DB::table('inventory_items')->get(['id', 'name', 'stock']) as $row) {
            $inventoryMap[normalizeInventoryName((string)($row->name ?? ''))] = $row;
        }

        foreach ($orderItems as $orderItem) {
            $itemName = normalizeInventoryName((string)($orderItem->notes ?? ''));
            if ($itemName === '' || !isset($inventoryMap[$itemName])) {
                continue;
            }
            $row = $inventoryMap[$itemName];
            $nextStock = max(0, (int)($row->stock ?? 0) + (int)($orderItem->quantity ?? 0));
            $nextStatus = $nextStock <= 0 ? 'Out of stock' : ($nextStock <= 5 ? 'Low stock' : 'In stock');
            DB::table('inventory_items')->where('id', $row->id)->update([
                'stock' => $nextStock,
                'status' => $nextStatus,
                'updated_at' => now(),
            ]);
        }

        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'cancelled',
            'payment_status' => 'cancelled',
            'cancelled_at' => now()->toDateTimeString(),
            'updated_at' => now(),
        ]);

        return ['success' => true, 'orderNumber' => $order->order_number];
    });

    if (!$result['success']) {
        http_response_code($result['status'] ?? 500);
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Unable to cancel order']);
        exit;
    }

    DB::table('order_activity_logs')->insert([
        'order_id' => $orderId,
        'order_number' => $result['orderNumber'] ?? null,
        'action' => 'order_cancelled',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => 'Order cancelled and stock restored',
        'details' => json_encode([
            'event' => 'Order cancelled',
            'cancelled_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    logApiEvent('order_cancelled', ['order_id' => $orderId, 'order_number' => $result['orderNumber'] ?? null]);

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'orderNumber' => $result['orderNumber'] ?? null,
        'status' => 'cancelled',
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to cancel order', 'details' => apiErrorDetail($error)]);
}
