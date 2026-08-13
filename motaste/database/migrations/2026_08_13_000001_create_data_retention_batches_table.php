<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('data_retention_batches')) {
            Schema::create('data_retention_batches', function (Blueprint $table) {
                $table->id();
                // Which archive stream this batch covers: logs | login_history | orders
                $table->string('batch_type', 40);
                // Human-readable period label, e.g. "2026-07" for a monthly window
                // or "2026-01" for the six-month order cutoff.
                $table->string('period_label', 20);
                // Records inside [period_start, period_end) belong to this batch.
                $table->timestamp('period_start')->nullable();
                $table->timestamp('period_end');
                $table->unsignedInteger('record_count')->default(0);
                // pending -> exported -> cleared (cleared means the rows were purged)
                $table->string('status', 20)->default('pending');
                $table->timestamp('notified_at')->nullable();
                $table->timestamp('exported_at')->nullable();
                $table->timestamp('cleared_at')->nullable();
                $table->timestamps();

                $table->index(['batch_type', 'status'], 'retention_batches_type_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_batches');
    }
};
