<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NameUtil;
use App\Services\OrderEventService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consolidated order domain endpoints.
 *
 * Replaces: public/api/create_order.php, get_pending_orders.php,
 * get_completed_orders.php, mark_order_complete.php,
 * update_pending_order_item.php, get_order_status.php,
 * order_events.php, get_order_logs.php
 */
class OrderController extends Controller
{
    /**
     * Guest order creation (no staff session required).
     * Port of create_order.php + delivery/lifecycle support.
     */
    public function create(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        $orderNumber = trim((string) ($input['orderNumber'] ?? ''));
        if ($orderNumber === '') {
            $orderNumber = (string) time();
        }

        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $paymentMethod = trim((string) ($input['paymentMethod'] ?? 'Cash'));
        $orderType = trim((string) ($input['orderType'] ?? 'Dine In'));

        $customerName = trim((string) ($input['customerName'] ?? ''));
        $customerPhone = trim((string) ($input['customerPhone'] ?? ''));
        $deliveryAddress = trim((string) ($input['deliveryAddress'] ?? ''));

        $subtotal = 0;
        foreach ($items as $it) {
            $subtotal += (float) ($it['price'] ?? 0) * (int) ($it['quantity'] ?? 0);
        }

        // Delivery orders carry a flat fee (defaults to 30 PHP; can be overridden).
        $deliveryFee = 0;
        if (stripos($orderType, 'delivery') !== false) {
            $deliveryFee = max(0, (float) ($input['deliveryFee'] ?? 30));
        }
        $total = $subtotal + $deliveryFee;

        try {
            $orderId = null;
            $insertedItems = 0;

            DB::transaction(function () use (&$orderId, &$insertedItems, $orderNumber, $paymentMethod, $orderType, $subtotal, $total, $deliveryFee, $items, $customerName, $customerPhone, $deliveryAddress) {
                $now = now();
                $orderData = [
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
                ];

                if (OrderService::hasOrderColumn('delivery_fee')) {
                    $orderData['delivery_fee'] = $deliveryFee;
                }
                if (OrderService::hasOrderColumn('customer_name')) {
                    $orderData['customer_name'] = $customerName !== '' ? $customerName : null;
                }
                if (OrderService::hasOrderColumn('customer_phone')) {
                    $orderData['customer_phone'] = $customerPhone !== '' ? $customerPhone : null;
                }
                if (OrderService::hasOrderColumn('delivery_address')) {
                    $orderData['delivery_address'] = $deliveryAddress !== '' ? $deliveryAddress : null;
                }

                $orderId = DB::table('orders')->insertGetId($orderData);

                foreach ($items as $it) {
                    $itemName = trim((string) ($it['name'] ?? 'Menu item'));
                    $price = (float) ($it['price'] ?? 0);
                    $qty = (int) ($it['quantity'] ?? 0);
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

                OrderEventService::create('order_created', $orderId, $orderNumber, $orderType, ['items' => $items]);
            });

            return response()->json(['success' => true, 'orderId' => $orderId, 'insertedItems' => $insertedItems]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Insert order failed', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of get_pending_orders.php + includes the preparing/ready lifecycle
     * stages so the dashboard can drive the full kitchen workflow.
     */
    public function pending(Request $request): JsonResponse
    {
        try {
            // Auto-expire pending orders untouched for 10+ minutes.
            $cutoff = now()->subMinutes(10);
            DB::table('orders')
                ->where('status', 'pending')
                ->where(function ($q) use ($cutoff) {
                    $q->where('updated_at', '<=', $cutoff)
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereNull('updated_at')->where('order_date', '<=', $cutoff);
                        });
                })
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);

            $orders = DB::table('orders')
                ->whereIn('status', ['pending', 'preparing', 'ready'])
                ->orderByDesc('order_date')
                ->limit(100)
                ->get();

            $result = $orders->map(fn ($order) => OrderService::transformOrder($order))->values()->all();

            return response()->json(['success' => true, 'orders' => $result]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Load pending orders failed', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of get_completed_orders.php.
     */
    public function completed(Request $request): JsonResponse
    {
        try {
            $orders = DB::table('orders')
                ->where('status', 'completed')
                ->orderByDesc('order_date')
                ->limit(500)
                ->get();

            $result = $orders->map(fn ($order) => OrderService::transformOrder($order))->values()->all();

            return response()->json(['success' => true, 'orders' => $result]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load completed orders', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of mark_order_complete.php (deducts stock, logs, emits event).
     */
    public function markComplete(Request $request): JsonResponse
    {
        $orderId = (int) $request->input('orderId', 0);
        $actor = $this->actorContext($request);

        if ($orderId <= 0) {
            return response()->json(['success' => false, 'error' => 'orderId is required'], 400);
        }

        try {
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

                $summary = OrderService::deductStockForOrder($orderId);

                DB::table('orders')
                    ->where('id', $orderId)
                    ->update([
                        'status' => 'completed',
                        'updated_at' => now(),
                    ]);
                OrderService::applyTransitionTimestamps($orderId, 'completed');

                return [
                    'success' => true,
                    'orderNumber' => $order->order_number,
                    'status' => 'completed',
                    'summary' => $summary,
                ];
            });

            if (!$result['success']) {
                return response()->json(['success' => false, 'error' => $result['error'] ?? 'Unable to mark order complete'], $result['status'] ?? 500);
            }

            if (!($result['alreadyCompleted'] ?? false)) {
                $this->logActivity([
                    'order_id' => $orderId,
                    'order_number' => $result['orderNumber'] ?? null,
                    'action' => 'order_completed',
                    'actor_role' => $actor['role'],
                    'actor_email' => $actor['email'],
                    'summary' => $result['summary'] ?? null,
                    'details' => ['event' => 'Order marked as complete', 'completed_at' => now()->toDateTimeString()],
                ]);

                $orderRow = DB::table('orders')->where('id', $orderId)->first();
                OrderEventService::create(
                    'order_completed',
                    $orderId,
                    $result['orderNumber'] ?? null,
                    $orderRow ? ($orderRow->order_type ?? '') : '',
                    ['summary' => $result['summary'] ?? null]
                );
            }

            return response()->json([
                'success' => true,
                'orderId' => $orderId,
                'orderNumber' => $result['orderNumber'] ?? null,
                'status' => 'completed',
                'alreadyCompleted' => (bool) ($result['alreadyCompleted'] ?? false),
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to mark order complete', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * New: transition an order through the lifecycle (preparing/ready/
     * completed/expired/cancelled) with timestamps, logging and events.
     */
    public function updateStatus(Request $request, int $orderId): JsonResponse
    {
        $status = strtolower(trim((string) $request->input('status', '')));
        $actor = $this->actorContext($request);

        if (!OrderService::isValidStatus($status)) {
            return response()->json(['success' => false, 'error' => 'Invalid order status'], 422);
        }

        try {
            $result = DB::transaction(function () use ($orderId, $status) {
                $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();

                if (!$order) {
                    return ['success' => false, 'status' => 404, 'error' => 'Order not found'];
                }

                $current = strtolower((string) $order->status);

                if ($status === $current) {
                    return ['success' => true, 'orderNumber' => $order->order_number, 'status' => $status, 'unchanged' => true];
                }

                // Terminal states cannot be reopened; a second completion would
                // double-deduct stock.
                if (in_array($current, ['completed', 'cancelled', 'expired'], true)) {
                    return [
                        'success' => false,
                        'status' => 409,
                        'error' => 'Order already '.$current.' and cannot be transitioned',
                    ];
                }

                // Completion is only valid from an active stage.
                if ($status === 'completed' && !in_array($current, ['pending', 'preparing', 'ready'], true)) {
                    return [
                        'success' => false,
                        'status' => 409,
                        'error' => 'Order cannot be completed from state '.$current,
                    ];
                }

                $summary = null;
                if ($status === 'completed') {
                    // First completion from an active stage — safe to deduct.
                    $summary = OrderService::deductStockForOrder($orderId);
                }

                DB::table('orders')
                    ->where('id', $orderId)
                    ->update([
                        'status' => $status,
                        'updated_at' => now(),
                    ]);
                OrderService::applyTransitionTimestamps($orderId, $status);

                return [
                    'success' => true,
                    'orderNumber' => $order->order_number,
                    'status' => $status,
                    'summary' => $summary,
                    'orderType' => (string) ($order->order_type ?? ''),
                ];
            });

            if (!$result['success']) {
                return response()->json(['success' => false, 'error' => $result['error'] ?? 'Unable to update order status'], $result['status'] ?? 500);
            }

            if (!($result['unchanged'] ?? false)) {
                $action = $status === 'completed' ? 'order_completed' : 'order_status_changed';
                $this->logActivity([
                    'order_id' => $orderId,
                    'order_number' => $result['orderNumber'] ?? null,
                    'action' => $action,
                    'actor_role' => $actor['role'],
                    'actor_email' => $actor['email'],
                    'summary' => $result['summary'] ?? ('Status changed to '.$status),
                    'details' => [
                        'from' => null,
                        'to' => $status,
                        'event_time' => now()->toDateTimeString(),
                    ],
                ]);

                OrderEventService::create(
                    $status === 'completed' ? 'order_completed' : 'order_status_changed',
                    $orderId,
                    $result['orderNumber'] ?? null,
                    $result['orderType'] ?? '',
                    ['status' => $status, 'summary' => $result['summary'] ?? null]
                );
            }

            return response()->json([
                'success' => true,
                'orderId' => $orderId,
                'orderNumber' => $result['orderNumber'] ?? null,
                'status' => $status,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to update order status', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * New: toggle payment status between unpaid and paid.
     */
    public function updatePayment(Request $request, int $orderId): JsonResponse
    {
        $paymentStatus = strtolower(trim((string) $request->input('payment_status', 'paid')));
        $actor = $this->actorContext($request);

        if (!in_array($paymentStatus, ['unpaid', 'paid'], true)) {
            return response()->json(['success' => false, 'error' => 'payment_status must be paid or unpaid'], 422);
        }

        try {
            $order = DB::table('orders')->where('id', $orderId)->first();
            if (!$order) {
                return response()->json(['success' => false, 'error' => 'Order not found'], 404);
            }

            $update = [
                'payment_status' => $paymentStatus,
                'updated_at' => now(),
            ];
            if ($paymentStatus === 'paid' && OrderService::hasOrderColumn('paid_at')) {
                $update['paid_at'] = now();
            }
            DB::table('orders')->where('id', $orderId)->update($update);

            $this->logActivity([
                'order_id' => $orderId,
                'order_number' => $order->order_number,
                'action' => $paymentStatus === 'paid' ? 'payment_marked_paid' : 'payment_marked_unpaid',
                'actor_role' => $actor['role'],
                'actor_email' => $actor['email'],
                'summary' => 'Payment '.$paymentStatus,
                'details' => ['payment_status' => $paymentStatus, 'event_time' => now()->toDateTimeString()],
            ]);

            return response()->json(['success' => true, 'orderId' => $orderId, 'payment_status' => $paymentStatus]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to update payment status', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of update_pending_order_item.php (quantity + component editing with
     * stock reservation checks).
     */
    public function updateItem(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        $orderId = (int) ($input['orderId'] ?? 0);
        $itemId = (int) ($input['itemId'] ?? 0);
        $quantity = array_key_exists('quantity', $input) ? (int) $input['quantity'] : null;
        $componentName = trim((string) ($input['componentName'] ?? ''));
        $componentQuantity = array_key_exists('componentQuantity', $input) ? (int) $input['componentQuantity'] : null;
        $componentsPayload = is_array($input['components'] ?? null) ? array_values($input['components']) : null;
        $actor = $this->actorContext($request);
        $hasComponentUpdate = $componentName !== '' && $componentQuantity !== null;
        $hasComponentsPayload = is_array($componentsPayload);

        if ($orderId <= 0 || $itemId <= 0
            || ($quantity === null && !$hasComponentUpdate && !$hasComponentsPayload)
            || ($quantity !== null && $quantity < 0)
            || ($hasComponentUpdate && $componentQuantity < 0)) {
            return response()->json(['success' => false, 'error' => 'orderId, itemId, and quantity or component update are required'], 400);
        }

        try {
            $normalizedComponentsPayload = [];
            if ($hasComponentsPayload) {
                foreach ($componentsPayload as $componentEntry) {
                    $componentNameValue = trim((string) ($componentEntry['name'] ?? ''));
                    $componentQuantityValue = max(0, (int) ($componentEntry['quantity'] ?? 0));
                    $normalizedName = NameUtil::normalizeItemName($componentNameValue);
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

                $previousQuantity = (int) ($targetItem->quantity ?? 0);
                $previousComponents = [];
                $currentComponents = OrderService::decodeComponents($targetItem->components ?? '') ?? [];

                if ($hasComponentsPayload) {
                    $currentComponents = $normalizedComponentsPayload;

                    $lineTotal = 0.0;
                    $inventoryItems = DB::table('inventory_items')->select('name', 'price')->get();
                    foreach ($currentComponents as $component) {
                        $componentNameValue = trim((string) ($component['name'] ?? ''));
                        $componentQuantityValue = max(0, (int) ($component['quantity'] ?? 0));
                        if ($componentNameValue === '' || $componentQuantityValue <= 0) {
                            continue;
                        }

                        foreach ($inventoryItems as $inventoryRow) {
                            if (NameUtil::normalizeItemName((string) ($inventoryRow->name ?? '')) === NameUtil::normalizeItemName($componentNameValue)) {
                                $lineTotal += $componentQuantityValue * (float) ($inventoryRow->price ?? 0);
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

                // Quantity stock-reservation check.
                $itemName = NameUtil::normalizeItemName((string) ($targetItem->notes ?? ''));
                if ($quantity !== null && $quantity > 0 && $itemName !== '') {
                    $inventoryItem = null;
                    $candidateInventory = DB::table('inventory_items')->select('id', 'stock', 'name')->get();
                    foreach ($candidateInventory as $inventoryRow) {
                        if (NameUtil::normalizeItemName((string) ($inventoryRow->name ?? '')) === $itemName) {
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
                            if (NameUtil::normalizeItemName((string) ($pendingRow->notes ?? '')) === $itemName) {
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
                    $normalizedComponentName = NameUtil::normalizeItemName($componentName);
                    $existingComponentIndex = null;
                    foreach ($currentComponents as $index => $component) {
                        if (NameUtil::normalizeItemName((string) ($component['name'] ?? '')) === $normalizedComponentName) {
                            $existingComponentIndex = $index;
                            break;
                        }
                    }
                    $previousComponents = $currentComponents;
                    $componentUnitPrice = 0.0;
                    $inventoryStock = null;
                    $inventoryComponent = DB::table('inventory_items')->select('price', 'name', 'stock')->get();
                    foreach ($inventoryComponent as $inventoryRow) {
                        if (NameUtil::normalizeItemName((string) ($inventoryRow->name ?? '')) === $normalizedComponentName) {
                            $componentUnitPrice = (float) ($inventoryRow->price ?? 0);
                            $inventoryStock = max(0, (int) ($inventoryRow->stock ?? 0));
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
                        $decodedComponents = OrderService::decodeComponents($pendingRow->components ?? '') ?? [];
                        foreach ($decodedComponents as $component) {
                            if (NameUtil::normalizeItemName((string) ($component['name'] ?? '')) === $normalizedComponentName) {
                                $reservedComponentQuantity += max(0, (int) ($component['quantity'] ?? 0));
                            }
                        }
                    }

                    $previousComponentQuantity = 0;
                    if ($existingComponentIndex !== null) {
                        $previousComponentQuantity = max(0, (int) ($currentComponents[$existingComponentIndex]['quantity'] ?? 0));
                    }

                    if ($inventoryStock !== null && $componentQuantity > max(0, $inventoryStock - $reservedComponentQuantity)) {
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
                        $normalizedComponentName = NameUtil::normalizeItemName((string) ($component['name'] ?? ''));
                        $componentQuantityValue = max(0, (int) ($component['quantity'] ?? 0));
                        if ($componentQuantityValue <= 0) {
                            continue;
                        }
                        foreach ($inventoryItems as $inventoryRow) {
                            if (NameUtil::normalizeItemName((string) ($inventoryRow->name ?? '')) === $normalizedComponentName) {
                                $computedLineTotal += $componentQuantityValue * (float) ($inventoryRow->price ?? 0);
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
                    'itemName' => (string) ($targetItem->notes ?? 'Menu item'),
                    'action' => $action,
                    'itemRemoved' => $itemRemoved,
                    'orderRemoved' => $orderRemoved,
                ];
            });

            if (!$result['success']) {
                return response()->json($result, $result['status'] ?? 500);
            }

            $this->logActivity([
                'order_id' => $orderId,
                'order_number' => $result['orderNumber'] ?? null,
                'action' => $result['action'] ?? 'quantity_updated',
                'actor_role' => $actor['role'],
                'actor_email' => $actor['email'],
                'summary' => ($result['itemName'] ?? 'Item').': '.(int) ($result['previousQuantity'] ?? 0).' -> '.(int) ($result['quantity'] ?? 0),
                'details' => [
                    'item' => $result['itemName'] ?? null,
                    'previous_quantity' => $result['previousQuantity'] ?? null,
                    'new_quantity' => $result['quantity'] ?? null,
                    'component_name' => $componentName !== '' ? $componentName : null,
                    'component_quantity' => $hasComponentUpdate ? $componentQuantity : null,
                    'subtotal' => $result['subtotal'] ?? null,
                    'event_time' => now()->toDateTimeString(),
                ],
            ]);

            return response()->json($result);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to update pending order item', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of get_order_status.php.
     */
    public function status(Request $request): JsonResponse
    {
        $orderNumbers = $request->json('orderNumbers');
        if (!is_array($orderNumbers)) {
            $orderNumbers = $request->input('orderNumbers', []);
        }

        if (!is_array($orderNumbers)) {
            return response()->json(['success' => false, 'error' => 'orderNumbers must be an array'], 400);
        }

        $normalizedOrderNumbers = array_values(array_filter(array_map(static function ($value) {
            return trim((string) $value);
        }, $orderNumbers), static function ($value) {
            return $value !== '';
        }));

        if (empty($normalizedOrderNumbers)) {
            return response()->json(['success' => true, 'orders' => []]);
        }

        try {
            $orders = DB::table('orders')
                ->whereIn('order_number', $normalizedOrderNumbers)
                ->orderByDesc('updated_at')
                ->get(['id', 'order_number', 'status', 'updated_at'])
                ->map(static function ($order) {
                    return [
                        'id' => (int) $order->id,
                        'order_number' => (string) $order->order_number,
                        'status' => (string) $order->status,
                        'updated_at' => $order->updated_at,
                    ];
                })
                ->values()
                ->all();

            return response()->json(['success' => true, 'orders' => $orders]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to fetch order status', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of order_events.php — Server-Sent Events stream.
     */
    public function events(Request $request): StreamedResponse
    {
        $lastId = 0;
        $lastEventId = (string) $request->header('Last-Event-ID', '');
        if ($lastEventId !== '') {
            $lastId = (int) $lastEventId;
        }
        $lastId = max($lastId, (int) $request->query('lastId', 0));

        return response()->stream(function () use ($lastId) {
            $currentId = $lastId;

            echo "retry: 2000\n\n";
            ob_flush();
            flush();

            $started = time();
            while (time() - $started < 50 && !connection_aborted()) {
                try {
                    $events = DB::table('order_events')
                        ->where('id', '>', $currentId)
                        ->orderBy('id')
                        ->limit(50)
                        ->get();

                    if ($events && count($events) > 0) {
                        foreach ($events as $ev) {
                            $currentId = (int) $ev->id;
                            $data = [
                                'id' => $ev->id,
                                'order_id' => $ev->order_id,
                                'order_number' => $ev->order_number,
                                'event_type' => $ev->event_type,
                                'order_type' => $ev->order_type,
                                'payload' => $ev->payload ? json_decode($ev->payload, true) : null,
                                'created_at' => $ev->created_at,
                            ];

                            $eventName = $ev->event_type === 'order_completed' ? 'order_completed' : 'order_created';
                            echo "id: {$ev->id}\n";
                            echo "event: {$eventName}\n";
                            echo 'data: '.json_encode($data)."\n\n";
                            ob_flush();
                            flush();
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore transient DB errors.
                }

                usleep(500000); // 0.5s
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Port of get_order_logs.php.
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $logs = DB::table('order_activity_logs')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => (int) ($row->id ?? 0),
                        'order_id' => $row->order_id !== null ? (int) $row->order_id : null,
                        'order_number' => $row->order_number,
                        'action' => (string) ($row->action ?? ''),
                        'actor_role' => $row->actor_role,
                        'actor_email' => $row->actor_email,
                        'summary' => $row->summary,
                        'details' => $row->details ? json_decode((string) $row->details, true) : null,
                        'created_at' => $row->created_at,
                        'created_at_iso' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                    ];
                })
                ->values()
                ->all();

            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load order logs', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Actor context from the authenticated staff session, falling back to
     * request-provided values for legacy front-end parity.
     */
    private function actorContext(Request $request): array
    {
        $staff = $request->session()->get('staff_session');
        if (is_array($staff) && ($staff['email'] ?? '') !== '') {
            return [
                'role' => (string) ($staff['role'] ?? 'Staff'),
                'email' => strtolower(trim((string) ($staff['email'] ?? ''))),
            ];
        }

        return [
            'role' => trim((string) $request->input('actorRole', 'Staff')) !== '' ? trim((string) $request->input('actorRole', 'Staff')) : 'Staff',
            'email' => strtolower(trim((string) $request->input('actorEmail', ''))) ?: null,
        ];
    }

    private function logActivity(array $entry): void
    {
        try {
            DB::table('order_activity_logs')->insert([
                'order_id' => $entry['order_id'] ?? null,
                'order_number' => $entry['order_number'] ?? null,
                'action' => $entry['action'] ?? '',
                'actor_role' => ($entry['actor_role'] ?? '') !== '' ? $entry['actor_role'] : null,
                'actor_email' => ($entry['actor_email'] ?? '') !== '' ? $entry['actor_email'] : null,
                'summary' => ($entry['summary'] ?? '') !== '' ? $entry['summary'] : null,
                'details' => is_array($entry['details'] ?? null)
                    ? json_encode($entry['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (($entry['details'] ?? null) !== null ? (string) $entry['details'] : null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never block the primary operation.
        }
    }
}
