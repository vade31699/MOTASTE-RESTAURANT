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

        if (!Schema::hasColumn('orders', 'prep_minutes')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('prep_minutes')->nullable()->after('delivery_address');
            });
        }

        if (!Schema::hasColumn('orders', 'prep_started_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('prep_started_at')->nullable()->after('prep_minutes');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'prep_started_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('prep_started_at');
            });
        }

        if (Schema::hasColumn('orders', 'prep_minutes')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('prep_minutes');
            });
        }
    }
};
