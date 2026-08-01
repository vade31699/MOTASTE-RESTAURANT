<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_reviews')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->index('publish_status', 'customer_reviews_publish_status_idx');
                $table->index('reviewer_key', 'customer_reviews_reviewer_key_idx');
                $table->index('reviewed_on', 'customer_reviews_reviewed_on_idx');
            });
        }

        if (Schema::hasTable('order_activity_logs')) {
            Schema::table('order_activity_logs', function (Blueprint $table) {
                $table->index('order_id', 'order_activity_logs_order_id_idx');
                $table->index('order_number', 'order_activity_logs_order_number_idx');
                $table->index('action', 'order_activity_logs_action_idx');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->index('order_id', 'order_items_order_id_idx');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('order_number', 'orders_order_number_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_reviews')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->dropIndex('customer_reviews_publish_status_idx');
                $table->dropIndex('customer_reviews_reviewer_key_idx');
                $table->dropIndex('customer_reviews_reviewed_on_idx');
            });
        }

        if (Schema::hasTable('order_activity_logs')) {
            Schema::table('order_activity_logs', function (Blueprint $table) {
                $table->dropIndex('order_activity_logs_order_id_idx');
                $table->dropIndex('order_activity_logs_order_number_idx');
                $table->dropIndex('order_activity_logs_action_idx');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropIndex('order_items_order_id_idx');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_order_number_idx');
            });
        }
    }
};
