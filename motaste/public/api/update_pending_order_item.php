<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireOrderManagerAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();



require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;

function ensureOrderLogsTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

$input = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['orderId']) ? (int) $input['orderId'] : 0;
$itemId = isset($input['itemId']) ? (int) $input['itemId'] : 0;
$quantity = isset($input['quantity']) ? (int) $input['quantity'] : null;
$componentName = trim((string)($input['componentName'] ?? ''));
$componentQuantity = array_key_exists('componentQuantity', $input) ? (int)$input['componentQuantity'] : null;
$componentsPayload = is_array($input['components'] ?? null) ? array_values($input['components']) : null;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));
$hasComponentUpdate = $componentName !== '' && $componentQuantity !== null;
$hasComponentsPayload = is_array($componentsPayload);

if ($orderId <= 0 || $itemId <= 0 || ($quantity === null && !$hasComponentUpdate && !$hasComponentsPayload) || ($quantity !== null && $quantity < 0) || ($hasComponentUpdate && $componentQuantity < 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderId, itemId, and quantity or component update are required']);
    exit;
}

try {
    ensureOrderLogsTable();

    $normalizedComponentsPayload = [];
    if ($hasComponentsPayload) {
        foreach ($componentsPayload as $componentEntry) {
            $componentNameValue = trim((string)($componentEntry['name'] ?? ''));
            $componentQuantityValue = max(0, (int)($componentEntry['quantity'] ?? 0));
            $normalizedName = normalizeItemName($componentNameValue);
            if ($normalizedName === '' || $componentQuantityValue <= 0) {
                continue;
            }

            if (!isset($normalizedComponentsPayload[$normalizedName])) {
                $normalizedComponentsPayload[$normalizedName] = [
                    'name' => $componentNameValue,
                    'quantity' => 0,
                ];
            }
            $normalizedComponentsPayload[$normalizedName]['quantity'] += $componentQuantityValue;
        }
        $normalizedComponentsPayload = array_values($normalizedComponentsPayload);
    }

    $result = DB::transaction(function () use ($orderId, $itemId, $quantity, $componentName, $componentQuantity, $hasComponentUpdate, $hasComponentsPayload, $normalizedComponentsPayload) {
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
        $previousComponents = [];
        $componentsJson = (string)($targetItem->components ?? '');
        $currentComponents = [];
        try {
            $decoded = json_decode($componentsJson, true);
            if (is_array($decoded)) {
                $currentComponents = array_values($decoded);
            }
        } catch (Throwable $e) {
            $currentComponents = [];
        }

        if ($hasComponentsPayload) {
            $currentComponents = $normalizedComponentsPayload;

            $lineTotal = 0.0;
            $inventoryItems = DB::table('inventory_items')->select('name', 'price')->get();
            foreach ($currentComponents as $component) {
                $componentNameValue = trim((string)($component['name'] ?? ''));
                $componentQuantityValue = max(0, (int)($component['quantity'] ?? 0));
                if ($componentNameValue === '' || $componentQuantityValue <= 0) {
                    continue;
                }

                foreach ($inventoryItems as $inventoryRow) {
                    if (normalizeItemName((string)($inventoryRow->name ?? '')) === normalizeItemName($componentNameValue)) {
                        $lineTotal += $componentQuantityValue * (float)($inventoryRow->price ?? 0);
                        break;
                    }
                }
            }

            if ($quantity !== null && $quantity > 0) {
                $unitPrice = $lineTotal / $quantity;
            } elseif ($previousQuantity > 0) {
                $unitPrice = $lineTotal / $previousQuantity;
            } else {
                $unitPrice = 0.0;
            }
        }

        $itemName = normalizeItemName((string) ($targetItem->notes ?? ''));
        if ($quantity !== null && $quantity > 0 && $itemName !== '') {
            $inventoryItem = null;
            $candidateInventory = DB::table('inventory_items')->select('id', 'stock', 'name')->get();
            foreach ($candidateInventory as $inventoryRow) {
                if (normalizeItemName((string)($inventoryRow->name ?? '')) === $itemName) {
                    $inventoryItem = $inventoryRow;
                    break;
                }
            }

            if ($inventoryItem) {
                $inventoryStock = max(0, (int) ($inventoryItem->stock ?? 0));

                $pendingRawItems = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.status', 'pending')
                    ->where('oi.id', '<>', $itemId)
                    ->select('oi.quantity', 'oi.notes')
                    ->get();

                $siblingReserved = 0;
                foreach ($pendingRawItems as $pendingRow) {
                    if (normalizeItemName((string)($pendingRow->notes ?? '')) === $itemName) {
                        $siblingReserved += (int) ($pendingRow->quantity ?? 0);
                    }
                }

                $maxAllowed = max(0, $inventoryStock - $siblingReserved);
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

        $lineTotal = (float) ($targetItem->line_total ?? 0);
        $unitPrice = (float) ($targetItem->unit_price ?? 0);
        $orderRemoved = false;
        $itemRemoved = false;
        $componentAction = null;

        if ($hasComponentUpdate) {
            $normalizedComponentName = normalizeItemName($componentName);
            $existingComponentIndex = null;
            foreach ($currentComponents as $index => $component) {
                if (normalizeItemName((string)($component['name'] ?? '')) === $normalizedComponentName) {
                    $existingComponentIndex = $index;
                    break;
                }
            }
            $previousComponents = $currentComponents;
            $componentUnitPrice = 0.0;
            $inventoryComponent = DB::table('inventory_items')->select('price', 'name')->get();
            foreach ($inventoryComponent as $inventoryRow) {
                if (normalizeItemName((string)($inventoryRow->name ?? '')) === $normalizedComponentName) {
                    $componentUnitPrice = (float)($inventoryRow->price ?? 0);
                    $inventoryStock = max(0, (int)($inventoryRow->stock ?? 0));
                    break;
                }
            }

            $pendingRawComponents = DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.status', 'pending')
                ->where('oi.id', '<>', $itemId)
                ->select('oi.components')
                ->get();

            $reservedComponentQuantity = 0;
            foreach ($pendingRawComponents as $pendingRow) {
                $decodedComponents = [];
                try {
                    $decodedComponents = json_decode((string)($pendingRow->components ?? ''), true);
                } catch (Throwable $e) {
                    $decodedComponents = [];
                }
                if (!is_array($decodedComponents)) {
                    continue;
                }
                foreach ($decodedComponents as $component) {
                    if (normalizeItemName((string)($component['name'] ?? '')) === $normalizedComponentName) {
                        $reservedComponentQuantity += max(0, (int)($component['quantity'] ?? 0));
                    }
                }
            }

            $previousComponentQuantity = 0;
            if ($existingComponentIndex !== null) {
                $previousComponentQuantity = max(0, (int)($currentComponents[$existingComponentIndex]['quantity'] ?? 0));
            }

            if (isset($inventoryStock) && $componentQuantity > max(0, $inventoryStock - $reservedComponentQuantity)) {
                return [
                    'success' => false,
                    'status' => 409,
                    'error' => 'Requested component quantity exceeds available stock',
                    'maxAllowed' => max(0, $inventoryStock - $reservedComponentQuantity),
                ];
            }

            $nextComponentQuantity = max(0, $componentQuantity);
            if ($existingComponentIndex !== null) {
                if ($nextComponentQuantity === 0) {
                    array_splice($currentComponents, $existingComponentIndex, 1);
                } else {
                    $currentComponents[$existingComponentIndex]['quantity'] = $nextComponentQuantity;
                }
            } elseif ($nextComponentQuantity > 0) {
                $currentComponents[] = [
                    'name' => $componentName,
                    'quantity' => $nextComponentQuantity,
                ];
            }

            $lineTotal += ($nextComponentQuantity - $previousComponentQuantity) * $componentUnitPrice;
            $lineTotal = max(0, $lineTotal);
            if ($previousQuantity > 0) {
                $unitPrice = $lineTotal / $previousQuantity;
            }
            $componentAction = 'component_quantity_updated';

            DB::table('order_items')
                ->where('id', $itemId)
                ->update([
                    'components' => json_encode($currentComponents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'updated_at' => now(),
                ]);
        }

        if ($hasComponentsPayload && !$hasComponentUpdate && $quantity === null) {
            $computedLineTotal = 0.0;
            $inventoryItems = DB::table('inventory_items')->select('name', 'price')->get();
            foreach ($currentComponents as $component) {
                $normalizedComponentName = normalizeItemName((string)($component['name'] ?? ''));
                $componentQuantityValue = max(0, (int)($component['quantity'] ?? 0));
                if ($componentQuantityValue <= 0) {
                    continue;
                }
                foreach ($inventoryItems as $inventoryRow) {
                    if (normalizeItemName((string)($inventoryRow->name ?? '')) === $normalizedComponentName) {
                        $computedLineTotal += $componentQuantityValue * (float)($inventoryRow->price ?? 0);
                        break;
                    }
                }
            }

            $lineTotal = max(0, $computedLineTotal);
            if ($previousQuantity > 0) {
                $unitPrice = $lineTotal / $previousQuantity;
            }
            DB::table('order_items')
                ->where('id', $itemId)
                ->update([
                    'components' => json_encode($currentComponents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'updated_at' => now(),
                ]);
        }

        if ($quantity !== null) {
            if ($quantity === 0) {
                DB::table('order_items')
                    ->where('id', $itemId)
                    ->delete();
                $itemRemoved = true;
            } else {
                if (!$hasComponentsPayload) {
                    $lineTotal = $unitPrice * $quantity;
                }

                $updateData = [
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'unit_price' => $unitPrice,
                    'updated_at' => now(),
                ];

                if ($hasComponentsPayload) {
                    $updateData['components'] = json_encode($currentComponents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                DB::table('order_items')
                    ->where('id', $itemId)
                    ->update($updateData);
            }
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
        } elseif ($hasComponentUpdate) {
            $action = 'component_quantity_updated';
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
        'summary' => ($result['itemName'] ?? 'Item') . ': ' . (int)($result['previousQuantity'] ?? 0) . ' -> ' . (int)($result['quantity'] ?? 0),
        'details' => json_encode([
            'item' => $result['itemName'] ?? null,
            'previous_quantity' => $result['previousQuantity'] ?? null,
            'new_quantity' => $result['quantity'] ?? null,
            'component_name' => $componentName !== '' ? $componentName : null,
            'component_quantity' => $hasComponentUpdate ? $componentQuantity : null,
            'subtotal' => $result['subtotal'] ?? null,
            'event_time' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode($result);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update pending order item']);
}