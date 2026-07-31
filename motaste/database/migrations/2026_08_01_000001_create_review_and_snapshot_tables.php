<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_reviews', function (Blueprint $table) {
            $table->id();
            $table->integer('rating');
            $table->text('review_text');
            $table->string('reviewer_key', 191)->nullable();
            $table->date('reviewed_on')->nullable();
            $table->string('publish_status', 20)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('review_daily_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('reviewer_key', 191);
            $table->date('blocked_on');
            $table->string('reason', 191)->nullable();
            $table->timestamps();
            $table->unique(['reviewer_key', 'blocked_on'], 'review_daily_blocks_reviewer_day_idx');
        });

        Schema::create('custom_menu_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_key', 191)->unique();
            $table->text('snapshot_payload');
            $table->timestamps();
        });

        Schema::create('highlights_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_key', 191)->unique();
            $table->text('snapshot_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('highlights_snapshots');
        Schema::dropIfExists('custom_menu_snapshots');
        Schema::dropIfExists('review_daily_blocks');
        Schema::dropIfExists('customer_reviews');
    }
};
