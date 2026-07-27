<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/csrf_guard.php';

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

    DB::statement("UPDATE customer_reviews SET publish_status = 'published' WHERE publish_status IS NULL");
    DB::statement("UPDATE customer_reviews SET reviewed_on = COALESCE(reviewed_on, DATE(created_at), CURRENT_DATE) WHERE reviewed_on IS NULL");

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
}

$input = json_decode(file_get_contents('php://input'), true);
$reviewId = isset($input['reviewId']) ? (int)$input['reviewId'] : 0;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

validateCsrfOrExit();

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

    DB::table('customer_reviews')
        ->where('id', $reviewId)
        ->update([
            'publish_status' => 'published',
            'published_at' => now(),
            'updated_at' => now(),
        ]);

    DB::table('order_activity_logs')->insert([
        'order_id' => null,
        'order_number' => null,
        'action' => 'review_published',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => 'Review published',
        'details' => json_encode([
            'review_id' => $reviewId,
            'rating' => (int)($review->rating ?? 0),
            'review_text' => (string)($review->review_text ?? ''),
            'published_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true, 'reviewId' => $reviewId, 'publish_status' => 'published']);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to publish review', 'details' => $error->getMessage()]);
}
