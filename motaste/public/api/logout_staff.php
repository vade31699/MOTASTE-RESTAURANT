<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_staff_auth_helpers.php';

// NOTE: logout deliberately does NOT require an active staff session. The
// PHP-native session can expire (or be garbage-collected) long before the user
// closes the tab; bailing out with 401 here would leave the HttpOnly session
// cookie intact, so the next page load would silently renew the session —
// logout would look like it worked but do nothing. CSRF validation below is
// what keeps this safe, and the endpoint can only ever revoke the token the
// caller already holds in their own cookie.
ensureStaffAuthSession();

require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);

validateCsrfOrExit();

// The token lives in the HttpOnly cookie; the request body is a legacy
// fallback for clients built before the cookie switch.
$token = resolveStaffSessionRequestToken($input['sessionToken'] ?? '');

// Resolve the account BEFORE revoking. Both the "online now" list and the
// audit entry below need the identity, and it has to survive a PHP session that
// is already gone (browser restarted, or the session was garbage-collected) —
// so the HttpOnly session token is the fallback.
$role = (string)($_SESSION['staff']['role'] ?? '');
$email = (string)($_SESSION['staff']['email'] ?? '');
if ($token !== null && ($email === '' || $role === '')) {
    $identity = resolveStaffSessionToken($token);
    if (is_array($identity)) {
        if ($email === '') {
            $email = (string)($identity['email'] ?? '');
        }
        if ($role === '') {
            $role = (string)($identity['role'] ?? '');
        }
    }
}

// The logout audit entry is written HERE rather than by the client's parallel
// notify_staff_session.php call: that call races the session teardown below and
// derives its actor from $_SESSION['staff'], so if this request is processed
// first the notification finds no session and records nothing — the logout
// would silently vanish from Logs > Account. The identity captured above (from
// the session, or the session token once the session is gone) is what makes
// this reliable.
recordStaffAccountActivity('logout', $role, $email, $input['occurredAt'] ?? null, $input['userAgent'] ?? null);

if ($token !== null) {
    revokeStaffSessionToken($token);
}

// Expire the cookie on the client so a later reload cannot renew the session.
setStaffSessionTokenCookie(null);

// Drop the staff member from the live "online now" list right away. Without
// this, logout leaves `last_active_at` fresh and they stay listed as online
// for the full 5-minute activity window.
markStaffOffline($email);

// Clear the PHP session data and expire the session cookie.
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

echo json_encode(['success' => true]);
