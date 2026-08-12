<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_staff_auth_helpers.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim((string)($input['sessionToken'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session token is required']);
    exit;
}

$identity = resolveStaffSessionToken($token);
if (!$identity) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Session expired or invalid. Please log in again.',
        'authRequired' => true,
    ]);
    exit;
}

// The staff account must still exist with the same role.
try {
    $staffRow = DB::table('staff')->whereRaw('LOWER(email) = ?', [$identity['email']])->first();
} catch (Throwable $dbError) {
    $staffRow = null;
}

if (!$staffRow || strtolower(trim((string)$staffRow->role)) !== strtolower(trim($identity['role']))) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'This account is no longer available. Please log in again.',
        'authRequired' => true,
    ]);
    exit;
}

// Re-establish the PHP-native session so staff-only endpoints recognize the user.
ensureStaffAuthSession();
session_regenerate_id(true);
$_SESSION['staff'] = [
    'role' => $identity['role'],
    'email' => $identity['email'],
    'name' => trim((string)($staffRow->full_name ?? '')),
    'logged_in_at' => now()->toDateTimeString(),
];

echo json_encode([
    'success' => true,
    'role' => $identity['role'],
    'email' => $identity['email'],
    'name' => trim((string)($staffRow->full_name ?? '')),
]);
