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

require $endpointFile;
