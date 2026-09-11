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


use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_password_policy.php';

$input = json_decode(file_get_contents('php://input'), true);
$currentEmail = strtolower(trim((string)($input['currentEmail'] ?? '')));
$currentPassword = (string)($input['currentPassword'] ?? '');
$newEmail = strtolower(trim((string)($input['newEmail'] ?? '')));
$newPassword = (string)($input['newPassword'] ?? '');

validateCsrfOrExit();

if ($currentEmail === '' || $currentPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Current email and password are required']);
    exit;
}

if ($newEmail === '' && $newPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A new email or password is required']);
    exit;
}

if ($newEmail !== '' && !preg_match('/@gmail\.com$/', $newEmail)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Admin email must be a Gmail address']);
    exit;
}

// Strong password policy: length, complexity, and common-password rejection.
// Admin password changes get the elevated 12-character minimum.
if ($newPassword !== '') {
    enforce_password_policy($newPassword, forAdmin: true);
}

try {
    // Validate current admin credentials against the admins table.
    $adminFound = findAdminAccountRow($currentEmail);
    $adminRow = $adminFound !== null ? $adminFound[1] : null;
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($currentPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin credentials are invalid']);
        exit;
    }

    // Reject reusing the current password: "changing" it to the value that is
    // already set would leave the existing credential valid. Checked here (at
    // request time) because only the plaintext is available to verify — the
    // pending value stored for confirmation is already hashed.
    if ($newPassword !== '' && is_password_reused($newPassword, [$adminRow->password_hash ?? null])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'New password must be different from the current password']);
        exit;
    }

    ensureAdminCredentialChangeTokensTable();

    $code = generateVerificationCode(6);
    $codeHash = hash('sha256', $code);
    $expiresAt = now()->addMinutes(3);
    $pendingEmail = $newEmail !== '' ? $newEmail : $currentEmail;

    // Security: the pending password is NEVER stored in plaintext. The final
    // bcrypt hash (with its unique per-password salt) is computed now, while
    // the plaintext password is still in memory, and only that hash is
    // persisted in the pending token. At confirmation time the hash is copied
    // into staff.password_hash as-is. This satisfies "do not store plaintext
    // passwords" end to end.
    $pendingPasswordHash = $newPassword !== ''
        ? password_hash($newPassword, PASSWORD_DEFAULT)
        : '';

    DB::table('admin_credential_change_tokens')
        ->where('current_email', $currentEmail)
        ->delete();

    DB::table('admin_credential_change_tokens')->insert([
        'current_email' => $currentEmail,
        'code_hash' => $codeHash,
        'pending_email' => $pendingEmail,
        'pending_password' => $pendingPasswordHash,
        'expires_at' => $expiresAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $emailBody = "MOTASTE admin credentials change request\n\n" .
        "Verification code: {$code}\n" .
        "Expires: " . $expiresAt->toDateTimeString() . "\n\n" .
        "If this was not requested by you, ignore this message immediately.";

    $emailResult = sendSystemEmail($currentEmail, 'MOTASTE Admin Credentials Verification Code', $emailBody);
    if (!$emailResult['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to send verification email', 'details' => $emailResult['error'] ?? 'Unknown mail error']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'warning' => $emailResult['warning'] ?? null,
        'mailDriver' => $emailResult['driver'] ?? null,
        'delivered' => array_key_exists('delivered', $emailResult) ? (bool)$emailResult['delivered'] : true,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to request credentials change']);
}
