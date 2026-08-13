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
require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();


use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_review_log_helpers.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $action = trim((string)($input['action'] ?? ''));
    if ($action === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'action is required']);
        exit;
    }

    $actorRole = trim((string)($input['actorRole'] ?? ''));
    $actorEmail = strtolower(trim((string)($input['actorEmail'] ?? '')));
    $summary = trim((string)($input['summary'] ?? ''));
    $details = $input['details'] ?? null;
    $orderId = isset($input['orderId']) ? (int)$input['orderId'] : null;
    $orderNumber = trim((string)($input['orderNumber'] ?? ''));

    // Route review-specific events to their dedicated container.
    $isReviewAction = strpos($action, 'review_') === 0;

    if ($isReviewAction) {
        writeReviewActivityLog([
            'review_id' => isset($details['review_id']) && is_array($details) ? (int)$details['review_id'] : null,
            'action' => $action,
            'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
            'actor_email' => $actorEmail !== '' ? $actorEmail : null,
            'summary' => $summary !== '' ? $summary : null,
            'details' => is_array($details) ? $details : ['raw' => $details],
        ]);
    } else {
        DB::table('order_activity_logs')->insert([
            'order_id' => $orderId,
            'order_number' => $orderNumber !== '' ? $orderNumber : null,
            'action' => $action,
            'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
            'actor_email' => $actorEmail !== '' ? $actorEmail : null,
            'summary' => $summary !== '' ? $summary : null,
            'details' => is_array($details)
                ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : ($details !== null ? (string)$details : null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save activity log']);
}
