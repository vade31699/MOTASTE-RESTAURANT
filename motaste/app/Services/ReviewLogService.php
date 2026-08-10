<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-specific activity logging (dedicated container).
 * Ported from the legacy public/api/_review_log_helpers.php.
 */
class ReviewLogService
{
    public static function ensureTable(): void
    {
        if (!Schema::hasTable('review_activity_logs')) {
            Schema::create('review_activity_logs', function ($table) {
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

    /**
     * Insert a review-specific activity log into the dedicated container.
     */
    public static function write(array $entry): void
    {
        self::ensureTable();

        $details = $entry['details'] ?? null;
        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        DB::table('review_activity_logs')->insert([
            'review_id' => isset($entry['review_id']) ? (int) $entry['review_id'] : null,
            'action' => trim((string) ($entry['action'] ?? '')),
            'actor_role' => ($entry['actor_role'] ?? '') !== '' ? (string) $entry['actor_role'] : null,
            'actor_email' => ($entry['actor_email'] ?? '') !== '' ? (string) $entry['actor_email'] : null,
            'summary' => ($entry['summary'] ?? '') !== '' ? (string) $entry['summary'] : null,
            'details' => $details !== null && $details !== '' ? (string) $details : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
