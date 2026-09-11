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

require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);
$event = strtolower(trim((string)($input['event'] ?? '')));

// SECURITY: the actor identity comes from the authenticated session, not the
// request body — otherwise a staff member could forge login/logout events for
// another account (including the Admin) in the audit trail and admin emails.
$sessionActor = is_array($_SESSION['staff'] ?? null) ? $_SESSION['staff'] : [];
$role = trim((string)($sessionActor['role'] ?? ''));
$email = strtolower(trim((string)($sessionActor['email'] ?? '')));
$occurredAt = trim((string)($input['occurredAt'] ?? ''));
$userAgent = trim((string)($input['userAgent'] ?? ''));

validateCsrfOrExit();

if (!in_array($event, ['login', 'logout'], true) || $role === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid notification payload']);
    exit;
}

// ---- Account activity audit trail ---------------------------------------
// Only the LOGIN entry is written from here. The logout entry belongs to
// logout_staff.php: this endpoint is called in parallel with the logout that
// tears the session down, so it can lose that race and be left with no session
// to attribute the event to (the row would go missing). Login has no such race,
// so it stays here next to the admin email.
if ($event === 'login') {
    recordStaffAccountActivity('login', $role, $email, $occurredAt, $userAgent);
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
    echo json_encode(['success' => false, 'error' => 'Unable to notify admin']);
}
