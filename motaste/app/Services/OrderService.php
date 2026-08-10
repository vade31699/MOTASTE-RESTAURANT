<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared order-domain logic: transforms, lifecycle transitions, payment and
 * stock deduction. Used by both the consolidated API controllers and the
 * new admin dashboard.
 */
class OrderService
{
    /**
     * Decode the components JSON column safely.
     */
    public static function decodeComponents($value): ?array
    {
        try {
            $decoded = json_decode((string) ($value ?? ''), true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static array $columnCache = [];

    /**
     * Memoized column check — Schema::hasColumn hits information_schema per
     * call on PostgreSQL, so cache per request to avoid hundreds of queries
     * when transforming large order lists.
     */
    public static function hasOrderColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }

        try {
            return self::$columnCache[$column] = Schema::hasColumn('orders', $column);
        } catch (\Throwable $e) {
            return self::$columnCache[$column] = false;
        }
    }

    /**
     * Transform an order row + its items into the API payload shape.
     */
    public static function transformOrder($order): array
    {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->get()
            ->map(function ($it) {
                $components = self::decodeComponents($it->components ?? '');

                return [
                    'id' => (int) $it->id,
                    'order_id' => (int) $it->order_id,
                    'name' => $it->notes ?: 'Menu item',
                    'notes' => $it->notes,
                    'price' => (float) ($it->unit_price ?? 0),
                    'unit_price' => (float) ($it->unit_price ?? 0),
                    'quantity' => (int) ($it->quantity ?? 0),
                    'line_total' => (float) ($it->line_total ?? 0),
                    'components' => is_array($components) ? $components : [],
                ];
            })
            ->values()
            ->all();

        $payload = [
            'id' => (int) $order->id,
            'order_number' => $order->order_number,
            'order_date' => $order->order_date,
            'order_date_iso' => $order->order_date ? Carbon::parse($order->order_date)->toIso8601String() : null,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'order_type' => $order->order_type,
            'subtotal' => (float) ($order->subtotal ?? 0),
            'total_amount' => (float) ($order->total_amount ?? 0),
            'total' => (float) ($order->total_amount ?? $order->total ?? 0),
            'items' => $items,
        ];

        // Include lifecycle timestamps / delivery fields when the schema has them.
        if (self::hasOrderColumn('delivery_fee')) {
            $payload['delivery_fee'] = (float) ($order->delivery_fee ?? 0);
        }
        if (self::hasOrderColumn('delivery_address')) {
            $payload['delivery_address'] = (string) ($order->delivery_address ?? '');
        }
        if (self::hasOrderColumn('customer_name')) {
            $payload['customer_name'] = (string) ($order->customer_name ?? '');
        }
        if (self::hasOrderColumn('customer_phone')) {
            $payload['customer_phone'] = (string) ($order->customer_phone ?? '');
        }
        if (self::hasOrderColumn('paid_at')) {
            $payload['paid_at'] = $order->paid_at;
        }
        foreach (['preparing_at', 'ready_at', 'completed_at'] as $col) {
            if (self::hasOrderColumn($col)) {
                $payload[$col] = $order->{$col};
            }
        }

        return $payload;
    }

    /**
     * Allowed lifecycle transitions (the value side may differ between stages).
     */
    public const ORDER_STATUSES = ['pending', 'preparing', 'ready', 'completed', 'cancelled', 'expired'];

    public static function isValidStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::ORDER_STATUSES, true);
    }

    /**
     * Deduct inventory stock for an order (idempotent guard handled by caller
     * via status check). Mirrors the legacy mark_order_complete.php behavior.
     *
     * @return string human-readable order summary
     */
    public static function deductStockForOrder(int $orderId): string
    {
        $orderItems = DB::table('order_items')
            ->where('order_id', $orderId)
            ->get(['notes', 'quantity']);

        $inventoryRows = DB::table('inventory_items')->get(['id', 'name', 'stock', 'status']);
        $inventoryMap = [];
        foreach ($inventoryRows as $inventoryRow) {
            $inventoryMap[NameUtil::normalizeItemName($inventoryRow->name)] = $inventoryRow;
        }

        foreach ($orderItems as $orderItem) {
            $itemName = NameUtil::normalizeItemName($orderItem->notes);
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

        return NameUtil::buildOrderSummary($orderItems);
    }

    /**
     * Apply a lifecycle transition and persist the matching timestamp column.
     */
    public static function applyTransitionTimestamps(int $orderId, string $status): void
    {
        $columnMap = [
            'preparing' => 'preparing_at',
            'ready' => 'ready_at',
            'completed' => 'completed_at',
            'cancelled' => 'cancelled_at',
            'expired' => 'expired_at',
            'paid' => 'paid_at',
        ];

        $column = $columnMap[$status] ?? null;
        if ($column !== null && self::hasOrderColumn($column)) {
            DB::table('orders')->where('id', $orderId)->update([
                $column => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
