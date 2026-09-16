<?php

/**
 * Subprocess runner for the authenticate_staff.php integration tests.
 *
 * PHPUnit cannot include the endpoint in-process: it is procedural PHP that
 * calls exit(), which would terminate the whole test runner. The test instead
 * launches a fresh `php` process executing THIS file, which requires the
 * (test-patched copy of the) REAL endpoint:
 *
 *  1. sets the $_SERVER superglobals the endpoint expects,
 *  2. buffers output and captures status + body in a shutdown function —
 *     shutdown functions run even when exit() terminates the script,
 *  3. requires the endpoint copy. The only substitution the test makes in the
 *     copy is file_get_contents('php://input') → TEST_INPUT_FILE, because
 *     php://input is always empty in CLI. All other code paths are untouched.
 *
 * Environment variables (set by the parent test process and inherited):
 *   TEST_INPUT_FILE    - path to the JSON request body
 *   TEST_RESPONSE_FILE - path where the JSON response envelope is written
 *   TEST_REMOTE_ADDR   - client IP to simulate
 *   TEST_ENDPOINT_FILE - path to the (patched) endpoint to execute
 */

$remoteAddr = getenv('TEST_REMOTE_ADDR');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = is_string($remoteAddr) && $remoteAddr !== '' ? $remoteAddr : '203.0.113.50';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Subprocess';
$_SERVER['REQUEST_URI'] = '/api/authenticate_staff.php';
$_SERVER['SCRIPT_NAME'] = '/api/authenticate_staff.php';

ob_start();
register_shutdown_function(function (): void {
    $output = '';
    while (ob_get_level() > 0) {
        $output .= ob_get_clean();
    }
    $status = http_response_code();
    file_put_contents(
        (string)getenv('TEST_RESPONSE_FILE'),
        json_encode([
            'status' => is_int($status) ? $status : 200,
            'body' => $output,
        ])
    );
});

$endpointFile = getenv('TEST_ENDPOINT_FILE');
if (!is_string($endpointFile) || $endpointFile === '' || !is_file($endpointFile)) {
    throw new RuntimeException('TEST_ENDPOINT_FILE must point to the patched endpoint copy');
}

// The endpoint is CSRF-protected (security requirement 3.4), and behaves like
// the browser the runner simulates: it must send a signed token in the
// X-CSRF-TOKEN header. Laravel CANNOT be pre-bootstrapped here — the endpoint
// re-requires bootstrap/app.php with require_once, whose second include returns
// `true` instead of the Application, crashing with "Call to a member function
// make() on true". So the runner mints the token itself:
//
//  1. APP_KEY is read straight from .env — the exact value the endpoint's
//     csrfSigningSecret() resolves to once IT bootstraps the framework, and
//  2. a PHP session is started NOW, so the endpoint reuses the same session ID
//     the token is bound to (isValidCsrfToken compares session IDs).
function csrfFixtureSigningSecret(string $root): string
{
    $key = '';
    $envFile = $root . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile) ?: [] as $line) {
            $line = trim($line);
            if (strpos($line, 'APP_KEY=') === 0) {
                $key = trim(substr($line, strlen('APP_KEY=')), '"' . "'");
                break;
            }
        }
    }
    if ($key === '') {
        $envKey = getenv('APP_KEY');
        if (is_string($envKey) && $envKey !== '') {
            $key = trim($envKey);
        }
    }
    if ($key !== '' && strpos($key, 'base64:') === 0) {
        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded !== false && $decoded !== '') {
            $key = $decoded;
        }
    }
    return $key === '' ? '' : 'motaste-csrf-v2|' . $key;
}

$__root = __DIR__ . '/../../..';
$__secret = csrfFixtureSigningSecret($__root);
if ($__secret === '') {
    throw new RuntimeException('APP_KEY unavailable: cannot mint a CSRF fixture token');
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
$__sessionId = session_id();
if (!is_string($__sessionId) || $__sessionId === '') {
    throw new RuntimeException('Unable to start the fixture session for the CSRF token');
}
$__nonce = bin2hex(random_bytes(24));
$__payload = (time() + 8 * 60 * 60) . '.' . $__nonce . '.' . $__sessionId;
$_SERVER['HTTP_X_CSRF_TOKEN'] = base64_encode($__payload) . '.' . hash_hmac('sha256', $__payload, $__secret);

require $endpointFile;
