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

    DB::table('customer_reviews')
        ->where('id', $reviewId)
        ->update([
            'publish_status' => 'published',
            'published_at' => now(),
            'updated_at' => now(),
        ]);

    writeReviewActivityLog([
        'review_id' => $reviewId,
        'action' => 'review_published',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => 'Review published',
        'details' => [
            'review_id' => $reviewId,
            'rating' => (int)($review->rating ?? 0),
            'review_text' => (string)($review->review_text ?? ''),
            'published_at' => now()->toDateTimeString(),
        ],
    ]);

    echo json_encode(['success' => true, 'reviewId' => $reviewId, 'publish_status' => 'published']);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to publish review', 'details' => $error->getMessage()]);
}
