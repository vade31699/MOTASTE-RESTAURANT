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

// No bearer token at all: the client still believes it has a staff session,
// but the HttpOnly token cookie is gone (expired, cleared, or the browser was
// restarted after a login that did not ask to be remembered). Answer with the
// standard authRequired shape so the dashboard returns to the login screen,
// instead of staying "logged in" while every staff-gated request 401s and the
// inventory / pending orders lists silently render empty.
if ($token === null) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Session token is missing. Please log in again.',
        'authRequired' => true,
    ]);
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
}

// Rotate the account's staff bearer token so the renewal does not leave the
// browser pointing at a token that was just invalidated. This keeps the
// current session valid for the next staff-only request while still preventing
// stale tokens from being replayed.
$freshToken = rotateStaffSessionToken($identity['email'], $identity['role'], $token);

// Re-establish the PHP-native session so staff-only endpoints recognize the user.
ensureStaffAuthSession();

// Regenerate the session ID so the old PHP session cannot be reused.
session_regenerate_id(true);

$_SESSION['staff'] = [
    'role' => $identity['role'],
    'email' => $identity['email'],
    'name' => trim((string)($staffRow->full_name ?? '')),
    'logged_in_at' => now()->toDateTimeString(),
];

$freshCsrf = function_exists('getOrCreateCsrfToken') ? getOrCreateCsrfToken() : '';
setStaffSessionTokenCookie($freshToken, true);

// Make the CURRENT request see the freshly rotated bearer token too.
$_COOKIE[STAFF_SESSION_COOKIE_NAME] = $freshToken;

echo json_encode([
    'success' => true,
    'role' => $identity['role'],
    'email' => $identity['email'],
    'name' => trim((string)($staffRow->full_name ?? '')),
    'csrfToken' => $freshCsrf,
]);
