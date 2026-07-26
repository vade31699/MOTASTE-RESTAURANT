<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function ensureReviewTables(): void
{
    DB::statement("CREATE TABLE IF NOT EXISTS customer_reviews (
        id BIGSERIAL PRIMARY KEY,
        rating INTEGER NOT NULL,
        review_text TEXT NOT NULL,
        reviewer_key VARCHAR(191) NULL,
        reviewed_on DATE NULL,
        publish_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        published_at TIMESTAMP NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    DB::statement("ALTER TABLE customer_reviews ADD COLUMN IF NOT EXISTS reviewer_key VARCHAR(191) NULL");
    DB::statement("ALTER TABLE customer_reviews ADD COLUMN IF NOT EXISTS reviewed_on DATE NULL");
    DB::statement("ALTER TABLE customer_reviews ADD COLUMN IF NOT EXISTS publish_status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    DB::statement("ALTER TABLE customer_reviews ADD COLUMN IF NOT EXISTS published_at TIMESTAMP NULL");

    DB::statement("CREATE TABLE IF NOT EXISTS order_activity_logs (
        id BIGSERIAL PRIMARY KEY,
        order_id BIGINT NULL,
        order_number VARCHAR(191) NULL,
        action VARCHAR(100) NOT NULL,
        actor_role VARCHAR(100) NULL,
        actor_email VARCHAR(191) NULL,
        summary TEXT NULL,
        details TEXT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    DB::statement("CREATE TABLE IF NOT EXISTS review_daily_blocks (
        id BIGSERIAL PRIMARY KEY,
        reviewer_key VARCHAR(191) NOT NULL,
        blocked_on DATE NOT NULL,
        reason VARCHAR(191) NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS review_daily_blocks_reviewer_day_idx ON review_daily_blocks (reviewer_key, blocked_on)");
}

$input = json_decode(file_get_contents('php://input'), true);
$reviewId = isset($input['reviewId']) ? (int)$input['reviewId'] : 0;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

if ($reviewId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'reviewId is required']);
    exit;
}

try {
    ensureReviewTables();

    $review = DB::table('customer_reviews')->where('id', $reviewId)->first();
    if (!$review) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Review not found']);
        exit;
    }

    $reviewerKey = (string)($review->reviewer_key ?? '');
    if ($reviewerKey !== '') {
        DB::table('review_daily_blocks')->updateOrInsert(
            [
                'reviewer_key' => $reviewerKey,
                'blocked_on' => now()->toDateString(),
            ],
            [
                'reason' => 'deleted_by_admin',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    DB::table('customer_reviews')->where('id', $reviewId)->delete();

    DB::table('order_activity_logs')->insert([
        'order_id' => null,
        'order_number' => null,
        'action' => 'review_deleted',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => 'Review deleted',
        'details' => json_encode([
            'review_id' => $reviewId,
            'rating' => (int)($review->rating ?? 0),
            'review_text' => (string)($review->review_text ?? ''),
            'reviewer_key' => $reviewerKey,
            'deleted_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to delete review', 'details' => $error->getMessage()]);
}