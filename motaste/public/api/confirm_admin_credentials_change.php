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

$input = json_decode(file_get_contents('php://input'), true);
$currentEmail = strtolower(trim((string)($input['currentEmail'] ?? '')));
$currentPassword = (string)($input['currentPassword'] ?? '');
$code = trim((string)($input['code'] ?? ''));

validateCsrfOrExit();

if ($currentEmail === '' || $currentPassword === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Current credentials and verification code are required']);
    exit;
}

try {
    ensureAdminCredentialChangeTokensTable();

    // Validate current admin credentials against the staff table
    $adminRow = DB::table('staff')->whereRaw('LOWER(email) = ?', [$currentEmail])->first();
    if (!$adminRow || !isset($adminRow->password_hash) || !password_verify($currentPassword, $adminRow->password_hash)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Current admin credentials are invalid']);
        exit;
    }

    $token = DB::table('admin_credential_change_tokens')
        ->where('current_email', $currentEmail)
        ->orderBy('id', 'desc')
        ->first();

    if (!$token) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No pending credentials change found']);
        exit;
    }

    if (now()->greaterThan($token->expires_at)) {
        DB::table('admin_credential_change_tokens')->where('id', $token->id)->delete();
        http_response_code(410);
        echo json_encode(['success' => false, 'error' => 'Verification code expired']);
        exit;
    }

    $hashedCode = hash('sha256', $code);
    if (!hash_equals((string)$token->code_hash, $hashedCode)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid verification code']);
        exit;
    }

    $newEmail = strtolower(trim((string)$token->pending_email));
    $newPassword = (string)$token->pending_password;
    $update = ['updated_at' => now()];

    if ($newEmail !== '' && $newEmail !== strtolower(trim((string)$adminRow->email))) {
        $update['email'] = $newEmail;
    }

    if ($newPassword !== '') {
        $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    DB::table('staff')->where('id', $adminRow->id)->update($update);

    DB::table('admin_credential_change_tokens')->where('id', $token->id)->delete();

    $accounts = loadStaffAccountsSnapshot();

    echo json_encode([
        'success' => true,
        'accounts' => $accounts,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to confirm credentials change', 'details' => apiErrorDetail($error)]);
}
