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
    $targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
    $adminPassword = (string)($input['adminPassword'] ?? '');
    $code = trim((string)($input['code'] ?? ''));

    validateCsrfOrExit();

    if ($targetEmail === '' || $adminPassword === '' || $code === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Target account, admin password, and verification code are required']);
        exit;
    }

    $adminFound = findAdminAccountRow();
    $adminRow = $adminFound !== null ? $adminFound[1] : null;
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($adminPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin password is incorrect']);
        exit;
    }

    // The account must have a pending setup to confirm.
    $pending = getTotpSettingRow($targetEmail);
    if (!$pending || (int) $pending->enabled !== 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Two-factor setup was not found or is already active']);
        exit;
    }

    // Re-derive the exact pending secret to store (the row holds the encrypted
    // value; confirmPendingTotpSecret validated it against the live app code).
    $secret = decryptTotpSecret((string) ($pending->secret ?? ''));
    if ($secret === '' || !confirmPendingTotpSecret($targetEmail, $code)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code']);
        exit;
    }

    activateTotpSecret($targetEmail, $secret);

    echo json_encode([
        'success' => true,
    ]);
} catch (Throwable $error) {
    error_log('totp_confirm failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to activate two-factor authentication']);
}