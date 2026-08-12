<?php
/**
 * E2E check of the "Prepare" flow:
 * 1. Login on a new device (code from log fallback) -> capture session token + cookie
 * 2. Renew session in a fresh jar (simulates the page reload behavior)
 * 3. Fetch CSRF token (needs the session cookie)
 * 4. Find a pending order
 * 5. Call start_order_preparation.php with orderId + minutes + CSRF header
 */
$base = 'http://localhost:8132';
$logFile = 'storage/logs/laravel.log';

function api(string $url, array $opts = [], ?string $jar = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if (!empty($opts['csrf'])) $headers[] = 'X-CSRF-TOKEN: ' . $opts['csrf'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (isset($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['post']));
    }
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string)$body, true)];
}

function codeFromLog(string $logFile): string {
    $lines = file($logFile) ?: [];
    foreach (array_reverse($lines) as $line) {
        if (preg_match('/Verification code: (\d{6})/', $line, $m)) return $m[1];
    }
    return '';
}

$device = 'prepare-check-' . bin2hex(random_bytes(5));
$login = api($base . '/api/authenticate_staff.php', ['post' => [
    'email' => 'dvidaddocs@gmail.com', 'password' => 'March1234', 'deviceToken' => $device,
]]);
echo '1) login: ' . json_encode($login['body']) . PHP_EOL;
if (empty($login['body']['needsDeviceVerification'])) { echo "FAIL: expected device verification\n"; exit(1); }

$code = codeFromLog($logFile);
$verify = api($base . '/api/verify_device_login.php', ['post' => [
    'email' => 'dvidaddocs@gmail.com', 'password' => 'March1234', 'code' => $code, 'deviceToken' => $device,
]]);
$token = (string)($verify['body']['sessionToken'] ?? '');
if ($token === '') { echo "FAIL: no session token\n"; exit(1); }
echo '2) verify OK, token len ' . strlen($token) . PHP_EOL;

// Fresh jar = page reload (session cookie gone)
$jar = '/tmp/prepare_jar.txt';
@unlink($jar);
$renew = api($base . '/api/renew_staff_session.php', [
    'post' => ['sessionToken' => $token],
], $jar);
echo '3) renew: HTTP ' . $renew['status'] . PHP_EOL;
if ($renew['status'] !== 200) { echo "FAIL: renew\n"; exit(1); }

// CSRF token
$csrf = api($base . '/api/get_csrf_token.php', [], $jar);
$csrfToken = (string)($csrf['body']['csrfToken'] ?? '');
echo '4) csrf token len: ' . strlen($csrfToken) . PHP_EOL;
if ($csrfToken === '') { echo "FAIL: no csrf\n"; exit(1); }

// Find a pending order
$pending = api($base . '/api/get_pending_orders.php', [], $jar);
$orders = $pending['body']['orders'] ?? [];
echo '5) pending orders: ' . count($orders) . PHP_EOL;
if (count($orders) === 0) {
    echo "NOTE: no pending orders to prepare — cannot exercise the endpoint. (This is OK if the restaurant has no pending orders.)\n";
    exit(0);
}
$target = $orders[0];
echo '   target order id=' . $target['id'] . ' number=' . ($target['order_number'] ?? '') . ' status=' . ($target['status'] ?? '') . PHP_EOL;

// Call Prepare
$prepare = api($base . '/api/start_order_preparation.php', [
    'post' => ['orderId' => (int)$target['id'], 'minutes' => 15, 'actorRole' => 'Admin', 'actorEmail' => 'dvidaddocs@gmail.com'],
    'csrf' => $csrfToken,
], $jar);
echo '6) PREPARE: HTTP ' . $prepare['status'] . ' ' . json_encode($prepare['body']) . PHP_EOL;

// Negative control: prepare WITHOUT csrf should fail
$noCsrf = api($base . '/api/start_order_preparation.php', [
    'post' => ['orderId' => (int)$target['id'], 'minutes' => 15, 'actorRole' => 'Admin', 'actorEmail' => 'dvidaddocs@gmail.com'],
], $jar);
echo '7) prepare without CSRF: HTTP ' . $noCsrf['status'] . ' ' . json_encode($noCsrf['body']) . PHP_EOL;

// Negative control: no session at all
$anon = api($base . '/api/start_order_preparation.php', [
    'post' => ['orderId' => (int)$target['id'], 'minutes' => 15, 'csrf' => 'abc'],
]);
echo '8) prepare anonymously: HTTP ' . $anon['status'] . ' ' . json_encode($anon['body']) . PHP_EOL;

echo "\nDone.\n";
