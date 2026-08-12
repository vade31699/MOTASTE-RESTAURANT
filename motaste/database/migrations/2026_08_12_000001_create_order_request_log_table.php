<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_request_log')) {
            Schema::create('order_request_log', function (Blueprint $table) {
                $table->id();
                $table->string('ip_address', 45)->nullable();
                $table->string('endpoint', 64)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['ip_address', 'endpoint', 'created_at'], 'order_request_log_ip_endpoint_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_request_log');
    }
};
