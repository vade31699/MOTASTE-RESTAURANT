<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('orders', 'customer_name')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('customer_name', 191)->nullable()->after('order_type');
            });
        }

        if (!Schema::hasColumn('orders', 'delivery_address')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('delivery_address')->nullable()->after('customer_name');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'delivery_address')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_address');
            });
        }

        if (Schema::hasColumn('orders', 'customer_name')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('customer_name');
            });
        }
    }
};
