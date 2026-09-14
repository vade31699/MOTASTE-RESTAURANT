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

// resolveStaffSessionToken() also enforces the inactivity window: a token that
// has not been used for STAFF_SESSION_IDLE_TIMEOUT_SECONDS (30 minutes by
// default) is deleted here, which is what signs an account out after the
// browser was closed or a tab was left untouched.
$identity = resolveStaffSessionToken($token);
if (!$identity) {
    $idle = function_exists('staffSessionIdleTimeoutTripped') && staffSessionIdleTimeoutTripped();
    $idleMinutes = function_exists('staffSessionIdleTimeoutSeconds')
        ? (int)round(staffSessionIdleTimeoutSeconds() / 60)
        : 30;

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => $idle
            ? 'Signed out after ' . $idleMinutes . ' minute' . ($idleMinutes === 1 ? '' : 's') . ' of inactivity. Please log in again.'
            : 'Session expired or invalid. Please log in again.',
        'authRequired' => true,
        'idleTimeout' => $idle,
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

// NOTE: the token is deliberately NOT rotated here.
//
// This endpoint runs on every page load, and the dashboard fires its gated
// requests (~8 of them) in parallel with it. Rotation revoked the token those
// already-dispatched requests were carrying, so they 401'd as a stale session;
// each 401 then triggered another renewal that revoked the token the previous
// one had just issued, and the cascade ended in a forced logout — a plain
// refresh bounced staff back to the login screen (and back through the emailed
// verification code). Keeping the token stable makes every in-flight request
// valid for the whole renewal.
//
// Tokens are still minted on login and revoked on logout or a credential
// change (revokeAllStaffSessionTokens), so nothing can replay a session the
// server has actually ended. The cookie is re-sent below so a login that did
// not ask to be remembered is upgraded to a persistent one by the first page
// load, keeping the browser and the 30-day PHP session cookie in step.

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

$response = [
    'success' => true,
    'role' => $identity['role'],
    'email' => $identity['email'],
    'name' => trim((string)($staffRow->full_name ?? '')),
    'csrfToken' => $freshCsrf,
    // The client watchdog signs the tab out after this many idle seconds, so it
    // follows the deployment's configured value instead of a hardcoded 30 min.
    'idleTimeoutSeconds' => staffSessionIdleTimeoutSeconds(),
];

// Re-issue the SAME token as a persistent cookie (the browser may have been
// holding a session-only one) so the session survives a browser restart.
setStaffSessionTokenCookie($token, true);

// Make the CURRENT request see the bearer token even when it only arrived in
// the legacy request body rather than the cookie.
$_COOKIE[STAFF_SESSION_COOKIE_NAME] = $token;

echo json_encode($response);
