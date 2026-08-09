<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('review_activity_logs')) {
            Schema::create('review_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_id')->nullable();
                $table->string('action', 100);
                $table->string('actor_role', 100)->nullable();
                $table->string('actor_email', 191)->nullable();
                $table->text('summary')->nullable();
                $table->text('details')->nullable();
                $table->timestamps();

                $table->index('review_id', 'review_activity_logs_review_id_idx');
                $table->index('action', 'review_activity_logs_action_idx');
                $table->index('actor_email', 'review_activity_logs_actor_email_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_activity_logs');
    }
};
