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

$full = trim((string)($input['name'] ?? ''));
$role = trim((string)($input['role'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

if (!$full || !$role || !$email || !$password) {
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

$existing = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->first();
if ($existing) {
    http_response_code(409);
    echo json_encode(['error' => 'A staff account with this email already exists']);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
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
    echo json_encode(['error' => 'Insert failed', 'details' => apiErrorDetail($error)]);
}
