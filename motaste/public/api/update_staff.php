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
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();

use Illuminate\Support\Facades\DB;

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$name = trim((string)($input['name'] ?? ''));
$role = trim((string)($input['role'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');
$currentEmail = strtolower(trim((string)($input['currentEmail'] ?? '')));
$id = (int)($input['id'] ?? 0);

if (!$name || !$role || !$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

$allowedRoles = ['Admin', 'Cashier', 'Inventory Manager'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid staff role']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(422);
    echo json_encode(['error' => 'Password must be at least 8 characters']);
    exit;
}

$lookupEmail = $currentEmail !== '' ? $currentEmail : $email;
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $query = DB::table('staff');

    if ($id > 0) {
        $query->where('id', $id);
    } else {
        $query->whereRaw('LOWER(email) = ?', [$lookupEmail]);
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
    echo json_encode(['error' => 'Update failed', 'details' => apiErrorDetail($error)]);
}
