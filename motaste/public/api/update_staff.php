<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

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

$name = isset($input['name']) ? trim($input['name']) : '';
$role = isset($input['role']) ? trim($input['role']) : '';
$email = isset($input['email']) ? strtolower(trim($input['email'])) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';
$passwordConfirmation = isset($input['password_confirmation']) ? (string)$input['password_confirmation'] : '';
$currentEmail = isset($input['currentEmail']) ? strtolower(trim($input['currentEmail'])) : '';
$id = isset($input['id']) ? (int) $input['id'] : 0;

if (!$name || !$role || !$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

// Stored-XSS guard: the staff name must be plain text.
rejectUnsafeInputOrExit($name);

// Password confirmation: the two fields must match exactly.
if ($password !== $passwordConfirmation) {
    http_response_code(422);
    echo json_encode(['error' => 'Password confirmation does not match']);
    exit;
}

// The Admin account cannot be created, edited, or promoted through this
// generic endpoint. Admin credentials are managed exclusively through the
// email-verified credentials flow (request/confirm_admin_credentials_change).
$allowedRoles = ['Cashier', 'Inventory Manager'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Only Cashier and Inventory Manager accounts can be updated here']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@gmail\.com$/', $email)) {
    http_response_code(422);
    echo json_encode(['error' => 'Staff email must be a Gmail address']);
    exit;
}

// Strong password policy: length, complexity, and common-password rejection.
enforce_password_policy($password);

$lookupEmail = $currentEmail ?: $email;
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $query = DB::table('staff');

    if ($id > 0) {
        $query->where('id', $id);
    } else {
        $query->whereRaw('LOWER(email) = ?', [$lookupEmail]);
    }

    $target = $query->first();
    if ($target && strtolower(trim((string)$target->role)) === 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'The Admin account cannot be updated here. Use the Credentials section instead.']);
        exit;
    }

    // Reject reusing the target's current password: "setting" it to the value
    // already stored would leave the existing credential valid.
    if ($target && is_password_reused($password, [$target->password_hash ?? null])) {
        http_response_code(422);
        echo json_encode(['error' => 'New password must be different from the account\'s current password']);
        exit;
    }

    $updated = $query->update([
        'full_name' => $name,
        'role' => $role,
        'email' => $email,
        'password_hash' => $hash,
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true, 'updated' => $updated]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed']);
}
