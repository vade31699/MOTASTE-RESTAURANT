<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Lightweight event records for real-time clients (SSE).
 * Ported from the on-demand table + insert pattern used across the legacy APIs.
 */
class OrderEventService
{
    public static function ensureTable(): void
    {
        if (!Schema::hasTable('order_events')) {
            Schema::create('order_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('order_number')->nullable()->index();
                $table->string('event_type', 64)->index();
                $table->string('order_type', 64)->nullable()->index();
                $table->text('payload')->nullable();
                $table->timestamps();
            });
        }
    }

    public static function create(
        string $eventType,
        ?int $orderId = null,
        ?string $orderNumber = null,
        ?string $orderType = null,
        $payload = null
    ): void {
        try {
            self::ensureTable();

            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'event_type' => $eventType,
                'order_type' => $orderType,
                'payload' => $payload !== null
                    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Event logging must never block the primary operation.
            error_log('order_events insert failed: '.$e->getMessage());
        }
    }
}
