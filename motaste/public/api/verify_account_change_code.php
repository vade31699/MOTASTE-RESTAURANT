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
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
$targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
$changeType = strtolower(trim((string)($input['changeType'] ?? '')));
$code = trim((string)($input['code'] ?? ''));

validateCsrfOrExit();

if ($targetEmail === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Target account and verification code are required']);
    exit;
}

if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($targetEmail) > 191) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Target account email address is invalid']);
    exit;
}

if (!preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Verification code must be 6 digits']);
    exit;
}

if (!in_array($changeType, ['email', 'password'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid change type']);
    exit;
}

try {
    ensureAccountChangeTokensTable();

    $token = DB::table('account_change_tokens')
        ->where('target_email', $targetEmail)
        ->where('change_type', $changeType)
        ->orderBy('id', 'desc')
        ->first();

    if (!$token) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No pending change request found']);
        exit;
    }

    if (now()->greaterThan($token->expires_at)) {
        DB::table('account_change_tokens')->where('id', $token->id)->delete();
        http_response_code(410);
        echo json_encode(['success' => false, 'error' => 'Verification code expired']);
        exit;
    }

    $hashedCode = hash('sha256', $code);
    if (!hash_equals((string)$token->code_hash, $hashedCode)) {
        $attempts = (int)($token->attempts ?? 0) + 1;
        if ($attempts >= 5) {
            DB::table('account_change_tokens')->where('id', $token->id)->delete();
        } else {
            DB::table('account_change_tokens')->where('id', $token->id)->update([
                'attempts' => $attempts,
                'updated_at' => now(),
            ]);
        }
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid verification code']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to verify account change code']);
}