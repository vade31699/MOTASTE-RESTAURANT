<?php

namespace App\Services;

/**
 * Shared string-normalization helpers for menu/inventory/order names.
 * Ported from the legacy public/api/_helpers.php.
 */
class NameUtil
{
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    public static function normalizeInventoryName(?string $value): string
    {
        return self::normalize($value);
    }

    public static function normalizeItemName(?string $value): string
    {
        return self::normalize($value);
    }

    /**
     * Build a short human-readable summary from an iterable of order item rows.
     * Accepts arrays or objects with `notes`/`quantity` fields.
     */
    public static function buildOrderSummary($orderItems): string
    {
        if (!is_iterable($orderItems)) {
            return '';
        }

        $parts = [];
        foreach ($orderItems as $it) {
            $name = '';
            $qty = 0;
            if (is_object($it)) {
                $name = (string) ($it->notes ?? '');
                $qty = (int) ($it->quantity ?? 0);
            } elseif (is_array($it)) {
                $name = (string) ($it['notes'] ?? '');
                $qty = (int) ($it['quantity'] ?? 0);
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $parts[] = $name.' x'.$qty;
        }

        return implode(', ', $parts);
    }
}
