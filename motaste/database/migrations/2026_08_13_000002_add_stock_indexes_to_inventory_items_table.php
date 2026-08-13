<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_items')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            // The low-stock filter (stock <= threshold) runs on the summary
            // endpoint, the low-stock email alert, and stock-restore paths.
            // Index the stock column so those COUNT/filter queries stay fast
            // as the inventory grows.
            $table->index('stock', 'inventory_items_stock_idx');
            $table->index('reorder_level', 'inventory_items_reorder_level_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_items')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex('inventory_items_stock_idx');
            $table->dropIndex('inventory_items_reorder_level_idx');
        });
    }
};
