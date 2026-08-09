<?php

use Illuminate\Support\Facades\DB;

/**
 * Ensure the dedicated review activity logs container exists.
 * Mirrors the on-demand Schema::create pattern used by other public APIs
 * (e.g. create_order.php) so new deployments work without running migrations.
 */
function ensureReviewActivityLogsTable(): void
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
 *
 * @param array{
 *   review_id?: int|null,
 *   action?: string,
 *   actor_role?: string|null,
 *   actor_email?: string|null,
 *   summary?: string|null,
 *   details?: string|array|null
 * } $entry
 */
function writeReviewActivityLog(array $entry): void
{
    ensureReviewActivityLogsTable();

    $details = $entry['details'] ?? null;
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    DB::table('review_activity_logs')->insert([
        'review_id' => isset($entry['review_id']) ? (int)$entry['review_id'] : null,
        'action' => trim((string)($entry['action'] ?? '')),
        'actor_role' => ($entry['actor_role'] ?? '') !== '' ? (string)$entry['actor_role'] : null,
        'actor_email' => ($entry['actor_email'] ?? '') !== '' ? (string)$entry['actor_email'] : null,
        'summary' => ($entry['summary'] ?? '') !== '' ? (string)$entry['summary'] : null,
        'details' => $details !== null && $details !== '' ? (string)$details : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
