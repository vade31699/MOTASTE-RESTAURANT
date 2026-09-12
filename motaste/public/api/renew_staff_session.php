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
require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);
// The token normally arrives in the HttpOnly cookie the browser sends
// automatically; the request body is a legacy fallback for older clients.
$token = resolveStaffSessionRequestToken($input['sessionToken'] ?? '');

if ($token === null) {
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
    $staffRow = findStaffAuthAccount($identity['email']);
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
}    // Revoke every previously issued bearer token for this account so an old
    // cookie cannot be replayed after renewal (session fixation / token reuse).
    revokeAllStaffSessionTokens($identity['email']);

    // Re-establish the PHP-native session so staff-only endpoints recognize the user.
    ensureStaffAuthSession();

    // Regenerate the session ID so the old PHP session cannot be reused.
    session_regenerate_id(true);

$_SESSION['staff'] = [
    'role' => $identity['role'],
    'email' => $identity['email'],
    'name' => trim((string)($staffRow->full_name ?? '')),
    'logged_in_at' => now()->toDateTimeString(),
];    $freshCsrf = function_exists('getOrCreateCsrfToken') ? getOrCreateCsrfToken() : '';

    // Make the CURRENT request see the newly issued bearer token. See the same
    // note in verify_device_login.php — setcookie() only takes effect on the
    // next request, but the caller may immediately hit a staff-gated endpoint
    // that requires the bearer token to match the (rehydrated) session.
    if (isset($_COOKIE[STAFF_SESSION_COOKIE_NAME]) === false) {
        $_COOKIE[STAFF_SESSION_COOKIE_NAME] = $token;
    }

    echo json_encode([
        'success' => true,
        'role' => $identity['role'],
        'email' => $identity['email'],
        'name' => trim((string)($staffRow->full_name ?? '')),
        'csrfToken' => $freshCsrf,
    ]);
