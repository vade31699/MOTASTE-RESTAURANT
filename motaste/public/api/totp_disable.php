<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_totp_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

try {
    $admin = requireAdminAuthOrExit();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    $targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
    $adminPassword = (string)($input['adminPassword'] ?? '');

    validateCsrfOrExit();

    if ($targetEmail === '' || $adminPassword === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Target account and current admin password are required']);
        exit;
    }
    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($targetEmail) > 191) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Target account email is invalid']);
        exit;
    }

    $adminFound = findAdminAccountRow();
    $adminRow = $adminFound !== null ? $adminFound[1] : null;
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($adminPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin password is incorrect']);
        exit;
    }

    if (!totpEnabledFor($targetEmail)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Two-factor authentication is not enabled for this account']);
        exit;
    }

    disableTotpFor($targetEmail);

    echo json_encode([
        'success' => true,
    ]);
} catch (Throwable $error) {
    error_log('totp_disable failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to disable two-factor authentication']);
}