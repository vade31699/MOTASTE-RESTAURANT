<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
$targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
$changeType = strtolower(trim((string)($input['changeType'] ?? '')));
$adminPassword = (string)($input['adminPassword'] ?? '');

validateCsrfOrExit();

if ($targetEmail === '' || $adminPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Target account and current admin password are required']);
    exit;
}

if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($targetEmail) > 191) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Target account email address is invalid']);
    exit;
}

if (!in_array($changeType, ['email', 'password'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid change type']);
    exit;
}

try {
    // Authorization: the admin must confirm the current admin password in
    // addition to holding the signed-in Admin session. The emailed code — sent
    // to the admin address — is the second factor that locks in the change.
    $adminFound = findAdminAccountRow();
    $adminRow = $adminFound !== null ? $adminFound[1] : null;
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($adminPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin password is incorrect']);
        exit;
    }

    // The target account must currently exist (staff or admin).
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

    $adminEmail = strtolower(trim((string)($adminRow->email ?? '')));
    if ($adminEmail === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Admin email is not configured']);
        exit;
    }

    ensureAccountChangeTokensTable();

    $code = generateVerificationCode(6);
    $codeHash = hash('sha256', $code);
    $expiresAt = now()->addMinutes(3);

    DB::table('account_change_tokens')
        ->where('target_email', $targetEmail)
        ->where('change_type', $changeType)
        ->delete();

    DB::table('account_change_tokens')->insert([
        'target_email' => $targetEmail,
        'change_type' => $changeType,
        'code_hash' => $codeHash,
        'expires_at' => $expiresAt,
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $changeLabel = $changeType === 'email' ? 'email address' : 'password';
    $targetLabel = ($target['name'] ?? 'Staff account') . ' (' . $targetEmail . ')';
    $emailBody = "MOTASTE account change authorization\n\n" .
        "An admin is requesting to change the {$changeLabel} of: {$targetLabel}\n" .
        "Verification code: {$code}\n" .
        "Expires: " . $expiresAt->toDateTimeString() . "\n\n" .
        "If this was not requested by you, ignore this message immediately.";

    $emailResult = sendSystemEmail($adminEmail, 'MOTASTE Account Change Verification Code', $emailBody);
    if (!$emailResult['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to send verification email']);
        exit;
    }

    echo json_encode([
        'success' => true,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to request account change']);
}