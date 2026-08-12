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

$email = strtolower(trim((string)($input['email'] ?? '')));
if ($email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing email']);
    exit;
}

try {
    $target = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->first();
    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Staff account not found']);
        exit;
    }

    // Never allow the last admin account to be deleted (would lock everyone out).
    if (strtolower(trim((string)($target->role ?? ''))) === 'admin') {
        $adminCount = (int) DB::table('staff')->whereRaw('LOWER(role) = ?', ['admin'])->count();
        if ($adminCount <= 1) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Cannot delete the last admin account']);
            exit;
        }
    }

    $deleted = DB::table('staff')->where('id', $target->id)->delete();

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed', 'details' => apiErrorDetail($error)]);
}
