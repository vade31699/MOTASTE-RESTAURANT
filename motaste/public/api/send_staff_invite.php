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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
$name = trim((string)($input['name'] ?? ''));
$role = trim((string)($input['role'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));

validateCsrfOrExit();

// Stored-XSS guard: the name and email are included in the invite email and
// audit trail.
rejectUnsafeInputOrExit($name, $email);

if ($name === '' || $role === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Name, role, and email are required']);
    exit;
}

// The invite must describe the same person the account will be created for, so
// the name has to satisfy the shared name policy here too.
$nameError = staffNameValidationError($name);
if ($nameError !== null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $nameError]);
    exit;
}
$name = normalizeStaffName($name);

if (!in_array($role, ['Cashier', 'Inventory Manager'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Only Cashier and Inventory Manager are supported']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@gmail\.com$/', $email)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Only Gmail addresses are allowed']);
    exit;
}

if (mb_strlen($email) > 191) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Email address is too long']);
    exit;
}

try {
    ensureStaffInviteTokensTable();

    $code = generateVerificationCode(6);
    $codeHash = hash('sha256', $code);
    $expiresAt = now()->addMinutes(20);

    DB::table('staff_invite_tokens')
        ->where('email', $email)
        ->where('role', $role)
        ->delete();

    DB::table('staff_invite_tokens')->insert([
        'email' => $email,
        'role' => $role,
        'code_hash' => $codeHash,
        'expires_at' => $expiresAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $body = "MOTASTE staff account invitation\n\n" .
        "Hello {$name},\n" .
        "You were added as {$role}.\n" .
        "Verification code: {$code}\n" .
        "Code expires: " . $expiresAt->toDateTimeString() . "\n\n" .
        "Use this code during your first login to confirm your account.";

    $emailResult = sendSystemEmail($email, 'MOTASTE Staff Invite Confirmation Code', $body);
    if (!$emailResult['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to send invite email']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to send invite']);
}
