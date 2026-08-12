<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}


use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim((string)($input['email'] ?? '')));
$role = trim((string)($input['role'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

validateCsrfOrExit();

if ($email === '' || $role === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email, role, and code are required']);
    exit;
}

try {
    ensureStaffInviteTokensTable();

    $token = DB::table('staff_invite_tokens')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->whereRaw('LOWER(role) = ?', [strtolower($role)])
        ->orderBy('id', 'desc')
        ->first();

    if (!$token) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No invite verification found']);
        exit;
    }

    if (now()->greaterThan($token->expires_at)) {
        DB::table('staff_invite_tokens')->where('id', $token->id)->delete();
        http_response_code(410);
        echo json_encode(['success' => false, 'error' => 'Invite verification code expired']);
        exit;
    }

    if (!hash_equals((string)$token->code_hash, hash('sha256', $code))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid invite verification code']);
        exit;
    }

    $accounts = loadStaffAccountsSnapshot();
    $updated = false;
    foreach ($accounts as &$account) {
        if (($account['email'] ?? '') === $email && ($account['role'] ?? '') === $role) {
            $account['inviteConfirmed'] = true;
            $updated = true;
            break;
        }
    }
    unset($account);

    if (!$updated) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Staff account not found']);
        exit;
    }

    saveStaffAccountsSnapshot($accounts);
    DB::table('staff_invite_tokens')->where('id', $token->id)->delete();

    echo json_encode([
        'success' => true,
        'accounts' => $accounts,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to confirm invite', 'details' => apiErrorDetail($error)]);
}
