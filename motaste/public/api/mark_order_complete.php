<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function ensureOrderLogsTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

function normalizeOrderItemName(?string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return mb_strtolower($value);
}

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
    ensureOrderLogsTable();

    $result = DB::transaction(function () use ($orderId) {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();

        if (!$order) {
            return ['success' => false, 'status' => 404, 'error' => 'Order not found'];
        }

        if (strtolower((string) $order->status) === 'completed') {
            return [
                'success' => true,
                'alreadyCompleted' => true,
                'orderNumber' => $order->order_number,
                'status' => 'completed',
            ];
        }

        $orderItems = DB::table('order_items')
            ->where('order_id', $orderId)
            ->get(['notes', 'quantity']);

        $inventoryRows = DB::table('inventory_items')->get(['id', 'name', 'stock', 'status']);
        $inventoryMap = [];
        foreach ($inventoryRows as $inventoryRow) {
            $inventoryMap[normalizeOrderItemName($inventoryRow->name)] = $inventoryRow;
        }

        foreach ($orderItems as $orderItem) {
            $itemName = normalizeOrderItemName($orderItem->notes);
            if ($itemName === '' || !isset($inventoryMap[$itemName])) {
                continue;
            }

            $inventoryRow = $inventoryMap[$itemName];
            $nextStock = max(0, (int) $inventoryRow->stock - (int) $orderItem->quantity);
            $nextStatus = $nextStock <= 0 ? 'Out of stock' : ($nextStock <= 5 ? 'Low stock' : 'In stock');

            DB::table('inventory_items')
                ->where('id', $inventoryRow->id)
                ->update([
                    'stock' => $nextStock,
                    'status' => $nextStatus,
                    'updated_at' => now(),
                ]);
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);

        $summary = buildOrderSummary($orderItems);

        return [
            'success' => true,
            'orderNumber' => $order->order_number,
            'status' => 'completed',
            'summary' => $summary,
        ];
    });

    if (!$result['success']) {
        http_response_code($result['status'] ?? 500);
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Unable to mark order complete']);
        exit;
    }

    if (!($result['alreadyCompleted'] ?? false)) {
        DB::table('order_activity_logs')->insert([
            'order_id' => $orderId,
            'order_number' => $result['orderNumber'] ?? null,
            'action' => 'order_completed',
            'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
            'actor_email' => $actorEmail !== '' ? $actorEmail : null,
            'summary' => $result['summary'] ?? null,
            'details' => json_encode([
                'event' => 'Order marked as complete',
                'completed_at' => now()->toDateTimeString(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'orderNumber' => $result['orderNumber'] ?? null,
        'status' => 'completed',
        'alreadyCompleted' => (bool) ($result['alreadyCompleted'] ?? false),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to mark order complete', 'details' => $error->getMessage()]);
}
