<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim((string)($input['sessionToken'] ?? ''));

validateCsrfOrExit();

if ($token !== '') {
    revokeStaffSessionToken($token);
}

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
