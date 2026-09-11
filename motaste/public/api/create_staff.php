<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_password_policy.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

validateCsrfOrExit();

$full = isset($input['name']) ? trim($input['name']) : '';
$role = isset($input['role']) ? trim($input['role']) : '';
$email = isset($input['email']) ? strtolower(trim($input['email'])) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';
$passwordConfirmation = isset($input['password_confirmation']) ? (string)$input['password_confirmation'] : '';

if (!$full || !$role || !$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

// Stored-XSS guard: the staff name must be plain text.
rejectUnsafeInputOrExit($full);

// Password confirmation: the two fields must match exactly.
if ($password !== $passwordConfirmation) {
    http_response_code(422);
    echo json_encode(['error' => 'Password confirmation does not match']);
    exit;
}

// Only Cashier / Inventory Manager accounts may be created through this
// endpoint. The Admin account is managed exclusively through the
// email-verified credentials flow (request/confirm_admin_credentials_change).
if (!in_array($role, ['Cashier', 'Inventory Manager'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Only Cashier and Inventory Manager accounts can be created here']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@gmail\.com$/', $email)) {
    http_response_code(422);
    echo json_encode(['error' => 'Staff email must be a Gmail address']);
    exit;
}

// Strong password policy: length, complexity, and common-password rejection.
enforce_password_policy($password);

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $existing = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->first();

    // The Admin lives in its own table — reject email collisions across both.
    if (!$existing && Schema::hasTable('admins')) {
        $existing = DB::table('admins')->whereRaw('LOWER(email) = ?', [$email])->exists();
    }

    if ($existing) {
        http_response_code(409);
        echo json_encode(['error' => 'A staff account with this email already exists']);
        exit;
    }

    $insertId = DB::table('staff')->insertGetId([
        'full_name' => $full,
        'role' => $role,
        'email' => $email,
        'password_hash' => $hash,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true, 'id' => $insertId]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Insert failed']);
}
