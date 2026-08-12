<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}


use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_review_log_helpers.php';

function ensureReviewTables(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

validateCsrfOrExit();

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

    writeReviewActivityLog([
        'review_id' => $reviewId,
        'action' => 'review_deleted',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => 'Review deleted',
        'details' => [
            'review_id' => $reviewId,
            'rating' => (int)($review->rating ?? 0),
            'review_text' => (string)($review->review_text ?? ''),
            'reviewer_key' => $reviewerKey,
            'deleted_at' => now()->toDateTimeString(),
        ],
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to delete review', 'details' => apiErrorDetail($error)]);
}