<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order numbers are customer-facing (used for tracking), so duplicates must
     * never exist. Existing duplicates (from concurrent client-generated
     * numbers) are suffixed with their row id before the unique index is added.
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $duplicates = DB::table('orders')
            ->select('order_number')
            ->whereNotNull('order_number')
            ->groupBy('order_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_number');

        foreach ($duplicates as $orderNumber) {
            $ids = DB::table('orders')
                ->where('order_number', $orderNumber)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach (array_slice($ids, 1) as $index => $id) {
                DB::table('orders')->where('id', $id)->update([
                    'order_number' => $orderNumber . '-' . ($index + 1) . '-' . $id,
                ]);
            }
        }

        if (!Schema::hasIndex('orders', ['order_number'])) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('order_number', 'orders_order_number_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasIndex('orders', ['order_number'])) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_order_number_unique');
            });
        }
    }
};
