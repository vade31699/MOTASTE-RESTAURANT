<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/_totp_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

try {
    // Only the Admin can enroll accounts in 2FA (the same role that manages
    // every other credential setting, keeping the portal's account flow
    // uniform).
    $admin = requireAdminAuthOrExit();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    $targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
    $adminPassword = (string)($input['adminPassword'] ?? '');

    // This generates no session and discloses only an authorization gate, but
    // it touches credentials, so the stateless signed token is still required.
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

    // Re-authenticate the admin with their password so a hijacked session
    // alone cannot enroll a secret the attacker controls.
    $adminFound = findAdminAccountRow();
    $adminRow = $adminFound !== null ? $adminFound[1] : null;
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($adminPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin password is incorrect']);
        exit;
    }

    // The target account must exist.
    $target = null;
    foreach (loadStaffAccountsSnapshot() as $account) {
        if (strtolower(trim((string)($account['email'] ?? ''))) === $targetEmail) {
            $target = $account;
            break;
        }
    }
    if ($target === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target account was not found']);
        exit;
    }

    $secret = generateTotpSecret();
    storePendingTotpSecret($targetEmail, $secret);

    echo json_encode([
        'success' => true,
        'secret' => $secret,
        'otpauth' => buildOtpauthUri($targetEmail, $secret),
    ]);
} catch (Throwable $error) {
    error_log('totp_setup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to set up two-factor authentication']);
}