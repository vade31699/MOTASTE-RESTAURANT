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

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_password_policy.php';

$input = json_decode(file_get_contents('php://input'), true);
$targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
$changeType = strtolower(trim((string)($input['changeType'] ?? '')));
$code = trim((string)($input['code'] ?? ''));
$newEmail = strtolower(trim((string)($input['newEmail'] ?? '')));
$newPassword = (string)($input['newPassword'] ?? '');
$newPasswordConfirmation = (string)($input['newPasswordConfirmation'] ?? '');

validateCsrfOrExit();

if ($targetEmail === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Target account and verification code are required']);
    exit;
}

if (!in_array($changeType, ['email', 'password'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid change type']);
    exit;
}

if ($changeType === 'email' && $newEmail === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A new email address is required']);
    exit;
}

if ($changeType === 'password' && $newPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A new password is required']);
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

    // Resolve the target account so the same flow serves staff and the admin.
    $snapshot = loadStaffAccountsSnapshot();
    $target = null;
    foreach ($snapshot as $account) {
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

    $isAdminTarget = strtolower(trim((string)($target['role'] ?? ''))) === 'admin';
    $accountTable = 'staff';
    $targetRow = null;

    if ($isAdminTarget) {
        $adminFound = findAdminAccountRow($targetEmail);
        $targetRow = $adminFound !== null ? $adminFound[1] : null;
        if ($adminFound !== null) {
            $accountTable = $adminFound[0];
        }
    }

    if ($targetRow === null) {
        $targetRow = DB::table('staff')->whereRaw('LOWER(email) = ?', [$targetEmail])->first();
        $accountTable = 'staff';
    }

    if (!$targetRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target account was not found in the database']);
        exit;
    }

    $update = ['updated_at' => now()];

    if ($changeType === 'email') {
        if (!preg_match('/@gmail\.com$/', $newEmail)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'New email must be a Gmail address']);
            exit;
        }

        // Stored-XSS guard: keep names/emails as plain text.
        rejectUnsafeInputOrExit('', $newEmail);

        if ($newEmail !== $targetEmail) {
            // Collision check across the staff and admins tables.
            foreach ($snapshot as $account) {
                $existing = strtolower(trim((string)($account['email'] ?? '')));
                if ($existing === $targetEmail) {
                    continue;
                }
                if ($existing === $newEmail) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'error' => 'That email is already registered to another account']);
                    exit;
                }
            }

            $update['email'] = $newEmail;
        }
    }

    if ($changeType === 'password') {
        if ($newPassword !== $newPasswordConfirmation) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Password confirmation does not match']);
            exit;
        }

        // Admin targets get the elevated 12-character minimum.
        enforce_password_policy($newPassword, forAdmin: $isAdminTarget);

        if (is_password_reused($newPassword, [$targetRow->password_hash ?? null])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'New password must be different from the current password']);
            exit;
        }

        $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (count($update) > 1) {
        DB::table($accountTable)->where('id', $targetRow->id)->update($update);
    }

    // Credentials changed: revoke every previously issued session token so old
    // sessions cannot linger with the previous email/password.
    revokeAllStaffSessionTokens($targetEmail);
    if ($changeType === 'email' && $newEmail !== '' && $newEmail !== $targetEmail) {
        revokeAllStaffSessionTokens($newEmail);
    }

    DB::table('account_change_tokens')->where('id', $token->id)->delete();

    $accounts = loadStaffAccountsSnapshot();

    echo json_encode([
        'success' => true,
        'accounts' => $accounts,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to confirm account change']);
}