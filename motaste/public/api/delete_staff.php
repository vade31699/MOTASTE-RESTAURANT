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
require_once __DIR__ . '/_totp_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';

$rawEmail = $input['email'] ?? null;
$email = is_string($rawEmail) ? trim($rawEmail) : '';
if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing email']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
    http_response_code(422);
    echo json_encode(['error' => 'Email address is invalid']);
    exit;
}

validateCsrfOrExit();

try {
    // The Admin lives in a separate `admins` table and must not be deleted
    // through the staff endpoint.
    if (function_exists('isAdminEmail') && isAdminEmail($email)) {
        http_response_code(403);
        echo json_encode(['error' => 'The Admin account cannot be deleted. Manage it through the Credentials section.']);
        exit;
    }

    $target = DB::table('staff')->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
    if ($target && strtolower(trim((string)$target->role)) === 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'The Admin account cannot be deleted. Manage it through the Credentials section.']);
        exit;
    }

    $deleted = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
        ->delete();

    // Drop any 2FA enrollment tied to the removed account so the secret set
    // cannot be left orphaned behind a deleted credential.
    try {
        disableTotpFor(strtolower(trim($email)));
    } catch (Throwable $totpError) {
        error_log('totp cleanup on staff delete failed: ' . $totpError->getMessage());
    }

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed']);
}
