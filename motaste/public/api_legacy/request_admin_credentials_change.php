<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

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

// Admin password policy: minimum 8 characters, no upper length limit.
if ($newPassword !== '' && mb_strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Admin password must be at least 8 characters']);
    exit;
}

try {
    // Validate current admin credentials against the staff table
    $adminRow = DB::table('staff')->whereRaw('LOWER(email) = ?', [$currentEmail])->first();
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($currentPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin credentials are invalid']);
        exit;
    }

    ensureAdminCredentialChangeTokensTable();

    $code = generateVerificationCode(6);
    $codeHash = hash('sha256', $code);
    $expiresAt = now()->addMinutes(10);
    $pendingEmail = $newEmail !== '' ? $newEmail : $currentEmail;
    $pendingPassword = $newPassword;

    DB::table('admin_credential_change_tokens')
        ->where('current_email', $currentEmail)
        ->delete();

    DB::table('admin_credential_change_tokens')->insert([
        'current_email' => $currentEmail,
        'code_hash' => $codeHash,
        'pending_email' => $pendingEmail,
        'pending_password' => $pendingPassword,
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
    echo json_encode(['success' => false, 'error' => 'Unable to request credentials change', 'details' => $error->getMessage()]);
}
