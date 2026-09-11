<?php

/**
 * Integration tests for the CAPTCHA gate in public/api/authenticate_staff.php.
 *
 * The endpoint is procedural PHP full of exit() calls, so it cannot be
 * included in-process (exit would kill PHPUnit). Each request instead runs in
 * a fresh `php` subprocess (see fixtures/) executing a test-patched copy of
 * the REAL endpoint:
 *
 *  - The ONLY substitution in the copy: file_get_contents('php://input') →
 *    file_get_contents(TEST_INPUT_FILE), because php://input is always empty
 *    in CLI. Everything else is byte-for-byte production code.
 *  - DB: a shared temp SQLite file. The parent Pest process never boots
 *    Laravel — seeding and cleanup also run in short-lived subprocesses
 *    (fixtures/authenticate_staff_seeder.php) so the process-wide DB config
 *    other unit tests rely on (in-memory SQLite) is never hijacked.
 *  - Email: SMTP env vars are blanked, so sendSystemEmail() uses the log
 *    fallback and never touches the network.
 *
 * Requests come from a single IP with a single email per test, so the only
 * suspicious-login signals that can fire are the raw failure counts under test.
 */

function captchaTestDbPath(): string
{
    static $path = null;
    if ($path === null) {
        $path = sys_get_temp_dir() . '/motaste_captcha_test_' . getmypid() . '.sqlite';
    }
    return $path;
}

/**
 * Write a patched copy of the endpoint into public/api/ (same directory depth
 * as the original, so its relative requires still resolve) and return its path.
 */
function preparePatchedEndpoint(): string
{
    static $path = null;
    if ($path !== null && is_file($path)) {
        return $path;
    }

    $source = (string)file_get_contents(__DIR__ . '/../../public/api/authenticate_staff.php');
    $needle = "file_get_contents('php://input')";
    $replacement = "file_get_contents(getenv('TEST_INPUT_FILE'))";
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('authenticate_staff.php input-read pattern not found for patching');
    }
    $patched = str_replace($needle, $replacement, $source);
    $path = __DIR__ . '/../../public/api/__test_authenticate_staff_' . getmypid() . '.php';
    file_put_contents($path, $patched);
    return $path;
}

/**
 * Base environment for every subprocess (endpoint runner and seeder alike).
 */
function captchaSubprocessEnv(array $extra = []): array
{
    return array_merge([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => captchaTestDbPath(),
        // Blank SMTP so the endpoint's email step uses the log fallback.
        'MAIL_HOST' => '',
        'MAIL_PORT' => '',
        'MAIL_USERNAME' => '',
        'MAIL_PASSWORD' => '',
        'MAIL_MAILER' => 'log',
        // The endpoint reads this via env() in the subprocess.
        'RECAPTCHA_V3_SECRET_KEY' => 'test-secret',
        // Windows: required by some PHP extensions in child processes.
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    ], $extra);
}

/**
 * Run a php subprocess and return [exitCode, stdout, stderr].
 */
function runPhpSubprocess(string $script, array $env): array
{
    $cmd = sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script));
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP subprocess');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

/**
 * Execute seeder actions in a subprocess (never in the parent process).
 */
function runCaptchaSeedActions(array $actions): void
{
    $resultFile = captchaTestDbPath() . '.seed-result.json';
    $inputFile = captchaTestDbPath() . '.seed.json';
    file_put_contents($inputFile, json_encode($actions));
    captchaUnlink($resultFile);

    [$exitCode, $stdout, $stderr] = runPhpSubprocess(
        __DIR__ . '/fixtures/authenticate_staff_seeder.php',
        captchaSubprocessEnv([
            'TEST_SEED_JSON' => $inputFile,
            'TEST_SEED_RESULT_FILE' => $resultFile,
        ])
    );

    $envelope = is_file($resultFile) ? json_decode((string)file_get_contents($resultFile), true) : null;
    captchaUnlink($inputFile);
    captchaUnlink($resultFile);

    if (!is_array($envelope) || ($envelope['ok'] ?? false) !== true) {
        throw new RuntimeException(
            "Seeder failed (exit={$exitCode}): " . ($envelope['error'] ?? 'no result') . "\nSTDOUT: {$stdout}\nSTDERR: {$stderr}"
        );
    }
}

function seedCaptchaStaff(string $email, string $password, string $role = 'Admin'): void
{
    runCaptchaSeedActions([
        ['action' => 'seedStaff', 'email' => $email, 'password' => $password, 'role' => $role],
    ]);
}

function clearCaptchaAttempts(): void
{
    // Clear every failed attempt from the test IP — simulating the lockout
    // window elapsing (the IP-scoped counter is time-bounded in production).
    // NOTE: clearing by email alone is NOT enough to disarm the gate because
    // the endpoint also counts IP-scoped failures (verified by this suite).
    runCaptchaSeedActions([
        ['action' => 'delete', 'table' => 'login_attempts', 'column' => 'ip_address', 'value' => '203.0.113.50'],
    ]);
}

/**
 * Unlink without surfacing Windows file-lock warnings to Pest.
 */
function captchaUnlink(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    set_error_handler(static function (): bool {
        return true;
    });
    try {
        unlink($file);
    } finally {
        restore_error_handler();
    }
}

/**
 * Execute the endpoint as if a browser posted $body from $remoteAddr.
 * Returns ['status' => int, 'body' => array].
 */
function runCaptchaSubprocess(array $jsonBody, string $remoteAddr = '203.0.113.50'): array
{
    $inputFile = captchaTestDbPath() . '.input.json';
    $responseFile = captchaTestDbPath() . '.response.json';

    file_put_contents($inputFile, json_encode($jsonBody));
    captchaUnlink($responseFile);

    [$exitCode, $stdout, $stderr] = runPhpSubprocess(
        __DIR__ . '/fixtures/authenticate_staff_subprocess_runner.php',
        captchaSubprocessEnv([
            'TEST_INPUT_FILE' => $inputFile,
            'TEST_RESPONSE_FILE' => $responseFile,
            'TEST_REMOTE_ADDR' => $remoteAddr,
            'TEST_ENDPOINT_FILE' => preparePatchedEndpoint(),
        ])
    );

    $envelope = is_file($responseFile) ? json_decode((string)file_get_contents($responseFile), true) : null;
    captchaUnlink($inputFile);
    captchaUnlink($responseFile);

    if (!is_array($envelope)) {
        throw new RuntimeException(
            "Subprocess produced no response (exit={$exitCode}).\nSTDOUT: {$stdout}\nSTDERR: {$stderr}"
        );
    }

    return [
        'status' => (int)$envelope['status'],
        'body' => json_decode((string)$envelope['body'], true) ?? [],
        'raw' => (string)$envelope['body'],
    ];
}

beforeAll(function () {
    // The parent process stays Laravel-free. Only ensure the endpoint copy
    // can be built and the temp DB file exists for the subprocesses.
    if (!is_file(captchaTestDbPath())) {
        touch(captchaTestDbPath());
    }
    preparePatchedEndpoint();
    // Tables are created by the first seeder/endpoint subprocess on demand.
});

afterAll(function () {
    foreach ([
        captchaTestDbPath(),
        captchaTestDbPath() . '.input.json',
        captchaTestDbPath() . '.response.json',
        captchaTestDbPath() . '.seed.json',
        captchaTestDbPath() . '.seed-result.json',
    ] as $file) {
        captchaUnlink($file);
    }
    // Remove the patched endpoint copy from public/api/.
    foreach (glob(__DIR__ . '/../../public/api/__test_authenticate_staff_*.php') ?: [] as $leftover) {
        captchaUnlink($leftover);
    }
});

beforeEach(function () {
    // Fresh slate per test so failure counts never leak between tests.
    runCaptchaSeedActions([
        ['action' => 'ensureSchema'],
        ['action' => 'delete', 'table' => 'login_attempts', 'column' => 'email', 'value' => '%'],
        ['action' => 'delete', 'table' => 'login_verification_tokens', 'column' => 'email', 'value' => '%'],
        ['action' => 'delete', 'table' => 'trusted_devices', 'column' => 'email', 'value' => '%'],
        ['action' => 'delete', 'table' => 'staff', 'column' => 'email', 'value' => '%'],
    ]);
});

test('captcha is not demanded on the first or second failed attempt', function () {
    $email = 'captcha-low@example.com';
    seedCaptchaStaff($email, 'Correct-Horse-1');

    ['status' => $firstStatus, 'body' => $firstBody] = runCaptchaSubprocess([
        'email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-a',
    ]);
    expect($firstStatus)->toBe(401);
    expect($firstBody['needsCaptcha'] ?? null)->toBeNull();
    // Messages are deliberately generic: no attempt-countdown hints.
    expect($firstBody['error'] ?? '')->toBe('Invalid username or Password.');
    expect($firstBody['remainingAttempts'] ?? null)->toBeNull();

    ['status' => $secondStatus, 'body' => $secondBody] = runCaptchaSubprocess([
        'email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-a',
    ]);
    expect($secondStatus)->toBe(401);
    expect($secondBody['needsCaptcha'] ?? null)->toBeNull();
});

test('captcha is demanded on the third attempt after two failures', function () {
    $email = 'captcha-trip@example.com';
    seedCaptchaStaff($email, 'Correct-Horse-2');

    runCaptchaSubprocess(['email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-b']);
    runCaptchaSubprocess(['email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-b']);

    // Third attempt — even with the CORRECT password — must be gated first.
    ['status' => $status, 'body' => $body] = runCaptchaSubprocess([
        'email' => $email, 'password' => 'Correct-Horse-2', 'role' => 'Admin', 'deviceToken' => 'tok-b',
    ]);

    expect($status)->toBe(422);
    expect($body['needsCaptcha'] ?? false)->toBeTrue();
    expect($body['success'] ?? true)->toBeFalse();
});

test('captcha gate blocks a correct password when armed and no token is provided', function () {
    $email = 'captcha-arm@example.com';
    seedCaptchaStaff($email, 'Correct-Horse-3');

    runCaptchaSubprocess(['email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-c']);
    runCaptchaSubprocess(['email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-c']);

    // Gate armed: correct password without a CAPTCHA token is refused 422.
    ['status' => $status, 'body' => $body] = runCaptchaSubprocess([
        'email' => $email, 'password' => 'Correct-Horse-3', 'role' => 'Admin', 'deviceToken' => 'tok-c',
    ]);

    expect($status)->toBe(422);
    expect($body['needsCaptcha'] ?? false)->toBeTrue();
});

test('two failures on a DIFFERENT account also arm the gate for this IP', function () {
    // The threshold counts IP-scoped failures too: another account's failures
    // from the same IP arm the CAPTCHA gate for every account from that IP.
    seedCaptchaStaff('captcha-victim@example.com', 'Victim-Pass-1');
    seedCaptchaStaff('captcha-other@example.com', 'Other-Pass-1');

    // Fail twice against account A from this IP.
    runCaptchaSubprocess(['email' => 'captcha-victim@example.com', 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-d']);
    runCaptchaSubprocess(['email' => 'captcha-victim@example.com', 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-d']);

    // First-ever attempt against account B from the SAME IP: the IP-scoped
    // counter (2) already meets the threshold.
    ['status' => $status, 'body' => $body] = runCaptchaSubprocess([
        'email' => 'captcha-other@example.com', 'password' => 'Other-Pass-1', 'role' => 'Admin', 'deviceToken' => 'tok-e',
    ]);

    expect($status)->toBe(422);
    expect($body['needsCaptcha'] ?? false)->toBeTrue();
});

test('a successful login clears failed attempts and rearms the gate from scratch', function () {
    // A real reCAPTCHA v3 pass cannot be simulated here (network call to
    // Google), so instead: arm the gate, verify it, then clear the
    // failure counter exactly as a successful login does (clearLoginAttempts)
    // and confirm the gate disarms and the login proceeds normally.
    $email = 'captcha-rearm@example.com';
    seedCaptchaStaff($email, 'Correct-Horse-4');

    runCaptchaSubprocess(['email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-f']);
    runCaptchaSubprocess(['email' => $email, 'password' => 'wrong', 'role' => 'Admin', 'deviceToken' => 'tok-f']);

    ['status' => $armedStatus, 'body' => $armedBody] = runCaptchaSubprocess([
        'email' => $email, 'password' => 'Correct-Horse-4', 'role' => 'Admin', 'deviceToken' => 'tok-f',
    ]);
    expect($armedStatus)->toBe(422);
    expect($armedBody['needsCaptcha'] ?? false)->toBeTrue();

    // Simulate the failure window elapsing: clear attempts from the test IP
    // (clearing by email alone leaves the IP-scoped counter armed).
    clearCaptchaAttempts();

    ['status' => $disarmedStatus, 'body' => $disarmedBody] = runCaptchaSubprocess([
        'email' => $email, 'password' => 'Correct-Horse-4', 'role' => 'Admin', 'deviceToken' => 'tok-f',
    ]);
    expect($disarmedStatus)->toBe(200);
    expect($disarmedBody['needsDeviceVerification'] ?? false)->toBeTrue();
});
