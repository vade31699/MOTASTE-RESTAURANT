<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';

$email = isset($input['email']) ? trim($input['email']) : '';
if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing email']);
    exit;
}

validateCsrfOrExit();

try {
    $target = DB::table('staff')->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
    if ($target && strtolower(trim((string)$target->role)) === 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'The Admin account cannot be deleted. Manage it through the Credentials section.']);
        exit;
    }

    $deleted = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
        ->delete();

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed']);
}
