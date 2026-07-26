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
    DB::statement("CREATE TABLE IF NOT EXISTS order_activity_logs (
        id BIGSERIAL PRIMARY KEY,
        order_id BIGINT NULL,
        order_number VARCHAR(191) NULL,
        action VARCHAR(100) NOT NULL,
        actor_role VARCHAR(100) NULL,
        actor_email VARCHAR(191) NULL,
        summary TEXT NULL,
        details TEXT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
}

function buildOrderSummaryByOrderId(int $orderId): string
{
    $items = DB::table('order_items')
        ->where('order_id', $orderId)
        ->get(['notes', 'quantity']);

    $parts = [];
    foreach ($items as $item) {
        $name = trim((string)($item->notes ?? 'Menu item'));
        $qty = (int)($item->quantity ?? 0);
        if ($qty <= 0) continue;
        $parts[] = $name . ' x' . $qty;
    }

    return implode(', ', $parts);
}

function normalizeItemName(?string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_strtolower($value);
}

$input = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['orderId']) ? (int) $input['orderId'] : 0;
$itemId = isset($input['itemId']) ? (int) $input['itemId'] : 0;
$quantity = isset($input['quantity']) ? (int) $input['quantity'] : 0;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

if ($orderId <= 0 || $itemId <= 0 || $quantity < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderId, itemId, and quantity are required']);
    exit;
}

try {
    ensureOrderLogsTable();

    $result = DB::transaction(function () use ($orderId, $itemId, $quantity) {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if (!$order) {
            return ['success' => false, 'status' => 404, 'error' => 'Order not found'];
        }

        if (strtolower((string) $order->status) !== 'pending') {
            return ['success' => false, 'status' => 409, 'error' => 'Only pending orders can be edited'];
        }

        $targetItem = DB::table('order_items')
            ->where('id', $itemId)
            ->where('order_id', $orderId)
            ->lockForUpdate()
            ->first();

        if (!$targetItem) {
            return ['success' => false, 'status' => 404, 'error' => 'Order item not found'];
        }

        $previousQuantity = (int)($targetItem->quantity ?? 0);

        $itemName = normalizeItemName((string) ($targetItem->notes ?? ''));
        if ($quantity > 0 && $itemName !== '') {
            $inventoryItem = DB::table('inventory_items')
                ->whereRaw("LOWER(REGEXP_REPLACE(TRIM(name), '\\s+', ' ', 'g')) = ?", [$itemName])
                ->first();

            if ($inventoryItem) {
                $inventoryStock = max(0, (int) ($inventoryItem->stock ?? 0));

                $siblingReserved = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.status', 'pending')
                    ->where('oi.id', '<>', $itemId)
                    ->whereRaw("LOWER(REGEXP_REPLACE(TRIM(oi.notes), '\\s+', ' ', 'g')) = ?", [$itemName])
                    ->sum('oi.quantity');

                $maxAllowed = max(0, $inventoryStock - (int) $siblingReserved);
                if ($quantity > $maxAllowed) {
                    return [
                        'success' => false,
                        'status' => 409,
                        'error' => 'Requested quantity exceeds available stock',
                        'maxAllowed' => $maxAllowed,
                    ];
                }
            }
        }

        $lineTotal = 0.0;
        $orderRemoved = false;
        $itemRemoved = false;
        if ($quantity === 0) {
            DB::table('order_items')
                ->where('id', $itemId)
                ->delete();
            $itemRemoved = true;
        } else {
            $unitPrice = (float) ($targetItem->unit_price ?? 0);
            $lineTotal = $unitPrice * $quantity;

            DB::table('order_items')
                ->where('id', $itemId)
                ->update([
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'updated_at' => now(),
                ]);
        }

        $totals = DB::table('order_items')->where('order_id', $orderId)
            ->selectRaw('COALESCE(SUM(line_total),0) as subtotal')
            ->first();

        $subtotal = (float) ($totals->subtotal ?? 0);

        $remainingItemCount = DB::table('order_items')
            ->where('order_id', $orderId)
            ->count();

        if ($remainingItemCount <= 0) {
            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'status' => 'cancelled',
                    'subtotal' => 0,
                    'total_amount' => 0,
                    'updated_at' => now(),
                ]);
            $orderRemoved = true;
        } else {
            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'subtotal' => $subtotal,
                    'total_amount' => $subtotal,
                    'updated_at' => now(),
                ]);
        }

        $action = 'quantity_updated';
        if ($orderRemoved) {
            $action = 'order_removed';
        } elseif ($itemRemoved) {
            $action = 'item_removed';
        } elseif ($quantity > $previousQuantity) {
            $action = 'quantity_increased';
        } elseif ($quantity < $previousQuantity) {
            $action = 'quantity_decreased';
        }

        return [
            'success' => true,
            'orderId' => $orderId,
            'orderNumber' => $order->order_number,
            'itemId' => $itemId,
            'quantity' => $quantity,
            'lineTotal' => $lineTotal,
            'subtotal' => $subtotal,
            'previousQuantity' => $previousQuantity,
            'itemName' => (string)($targetItem->notes ?? 'Menu item'),
            'action' => $action,
            'itemRemoved' => $itemRemoved,
            'orderRemoved' => $orderRemoved,
        ];
    });

    if (!$result['success']) {
        http_response_code($result['status'] ?? 500);
        echo json_encode($result);
        exit;
    }

    DB::table('order_activity_logs')->insert([
        'order_id' => $orderId,
        'order_number' => $result['orderNumber'] ?? null,
        'action' => $result['action'] ?? 'quantity_updated',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => buildOrderSummaryByOrderId($orderId),
        'details' => json_encode([
            'item' => $result['itemName'] ?? null,
            'previous_quantity' => $result['previousQuantity'] ?? null,
            'new_quantity' => $result['quantity'] ?? null,
            'subtotal' => $result['subtotal'] ?? null,
            'event_time' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode($result);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update pending order item', 'details' => $error->getMessage()]);
}