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

function resolveClientIpAddress(): string
{
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string)($parts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote !== '' ? $remote : 'unknown';
}

$input = json_decode(file_get_contents('php://input'), true);
$rating = isset($input['rating']) ? (int)$input['rating'] : 0;
$reviewText = trim((string)($input['reviewText'] ?? ''));
$reviewerToken = trim((string)($input['reviewerToken'] ?? ''));

if ($rating < 1 || $rating > 5 || $reviewText === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'rating and reviewText are required']);
    exit;
}

try {
    ensureReviewTables();

    $reviewerKeySource = $reviewerToken !== '' ? 'token:' . $reviewerToken : 'ip:' . resolveClientIpAddress();
    $reviewerKey = hash('sha256', $reviewerKeySource);
    $today = now()->toDateString();

    $submittedTodayCount = (int)DB::table('customer_reviews')
        ->where('reviewer_key', $reviewerKey)
        ->whereDate('reviewed_on', $today)
        ->count();

    $publishedTodayCount = (int)DB::table('customer_reviews')
        ->where('reviewer_key', $reviewerKey)
        ->whereDate('reviewed_on', $today)
        ->where('publish_status', 'published')
        ->count();

    // Base allowance is one review per day; each same-day published review unlocks one more.
    $allowedSubmissionsToday = 1 + $publishedTodayCount;
    if ($submittedTodayCount >= $allowedSubmissionsToday) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'You have reached your review limit for today. Submit again after staff publishes your latest review, or try again tomorrow.'
        ]);
        exit;
    }

    $reviewId = DB::table('customer_reviews')->insertGetId([
        'rating' => $rating,
        'review_text' => $reviewText,
        'reviewer_key' => $reviewerKey,
        'reviewed_on' => $today,
        'publish_status' => 'pending',
        'published_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_activity_logs')->insert([
        'order_id' => null,
        'order_number' => null,
        'action' => 'review_submitted_pending',
        'actor_role' => 'Customer',
        'actor_email' => null,
        'summary' => 'Customer review submitted (pending publish)',
        'details' => json_encode([
            'review_id' => $reviewId,
            'rating' => $rating,
            'review_text' => $reviewText,
            'publish_status' => 'pending',
            'submitted_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode([
        'success' => true,
        'reviewId' => $reviewId,
        'publishStatus' => 'pending',
        'message' => 'Review submitted. It will appear after staff approval.'
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save review', 'details' => $error->getMessage()]);
}