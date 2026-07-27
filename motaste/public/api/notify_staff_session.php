<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);
$event = strtolower(trim((string)($input['event'] ?? '')));
$role = trim((string)($input['role'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$occurredAt = trim((string)($input['occurredAt'] ?? ''));
$userAgent = trim((string)($input['userAgent'] ?? ''));

validateCsrfOrExit();

if (!in_array($event, ['login', 'logout'], true) || $role === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid notification payload']);
    exit;
}

if (!in_array($role, ['Cashier', 'Inventory Manager'], true)) {
    echo json_encode(['success' => true, 'skipped' => true]);
    exit;
}

try {
    $accounts = loadStaffAccountsSnapshot();
    $admin = getAdminAccount($accounts);
    if (!$admin || empty($admin['email'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Admin email not configured']);
        exit;
    }

    $action = $event === 'login' ? 'logged in' : 'logged out';
    $subject = sprintf('MOTASTE Notification: %s %s', $role, ucfirst($event));
    $body = "MOTASTE Staff Session Notification\n\n" .
        "Role: {$role}\n" .
        "Staff Email: {$email}\n" .
        "Action: {$action}\n" .
        "Date/Time: " . ($occurredAt !== '' ? $occurredAt : now()->toDateTimeString()) . "\n" .
        "User Agent: {$userAgent}\n";

    $emailResult = sendSystemEmail((string)$admin['email'], $subject, $body);
    if (!$emailResult['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to send notification email', 'details' => $emailResult['error'] ?? 'Unknown mail error']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to notify admin', 'details' => $error->getMessage()]);
}
