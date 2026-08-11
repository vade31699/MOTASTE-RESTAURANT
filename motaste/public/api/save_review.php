<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_review_log_helpers.php';
// Provides resolveClientIpAddress() used for the anonymous reviewer key fallback.
require_once __DIR__ . '/_device_auth_helpers.php';

function ensureReviewTables(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

$input = json_decode(file_get_contents('php://input'), true);
$rating = isset($input['rating']) ? (int)$input['rating'] : 0;
$reviewText = trim((string)($input['reviewText'] ?? ''));
$reviewerToken = trim((string)($input['reviewerToken'] ?? ''));

validateCsrfOrExit();

$reviewText = strip_tags($reviewText);
$reviewText = preg_replace('/\s+/u', ' ', $reviewText);
$reviewText = trim((string)$reviewText);

if ($rating < 1 || $rating > 5 || $reviewText === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'rating and reviewText are required']);
    exit;
}

if (mb_strlen($reviewText) > 500) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Review must be 500 characters or fewer']);
    exit;
}

try {
    ensureReviewTables();

    $reviewerKeySource = $reviewerToken !== '' ? 'token:' . $reviewerToken : 'ip:' . resolveClientIpAddress();
    $reviewerKey = hash('sha256', $reviewerKeySource);
    $today = now()->toDateString();

    $isBlockedToday = DB::table('review_daily_blocks')
        ->where('reviewer_key', $reviewerKey)
        ->whereDate('blocked_on', $today)
        ->exists();

    if ($isBlockedToday) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Your review was removed by staff today. You can submit a new review tomorrow.'
        ]);
        exit;
    }

    $submittedTodayCount = (int)DB::table('customer_reviews')
        ->where('reviewer_key', $reviewerKey)
        ->whereDate('reviewed_on', $today)
        ->count();

    $allowedSubmissionsToday = 1;
    if ($submittedTodayCount >= $allowedSubmissionsToday) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'You have reached your review limit for today. Submit again tomorrow.'
        ]);
        exit;
    }

    $reviewId = DB::table('customer_reviews')->insertGetId([
        'rating' => $rating,
        'review_text' => $reviewText,
        'reviewer_key' => $reviewerKey,
        'reviewed_on' => $today,
        'publish_status' => 'published',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    writeReviewActivityLog([
        'review_id' => $reviewId,
        'action' => 'review_submitted',
        'actor_role' => 'Customer',
        'actor_email' => null,
        'summary' => 'Customer review submitted and published',
        'details' => [
            'review_id' => $reviewId,
            'rating' => $rating,
            'review_text' => $reviewText,
            'publish_status' => 'published',
            'submitted_at' => now()->toDateTimeString(),
        ],
    ]);

    echo json_encode([
        'success' => true,
        'reviewId' => $reviewId,
        'publishStatus' => 'published',
        'message' => 'Review submitted and published. It will appear immediately.'
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save review', 'details' => $error->getMessage()]);
}