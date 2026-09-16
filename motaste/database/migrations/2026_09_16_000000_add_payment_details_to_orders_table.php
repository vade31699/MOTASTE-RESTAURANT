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

        if (!Schema::hasColumn('orders', 'payment_received')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('payment_received', 12, 2)->nullable()->after('total_amount');
            });
        }

        if (!Schema::hasColumn('orders', 'change_due')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('change_due', 12, 2)->nullable()->after('payment_received');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'change_due')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('change_due');
            });
        }

        if (Schema::hasColumn('orders', 'payment_received')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('payment_received');
            });
        }
    }
};