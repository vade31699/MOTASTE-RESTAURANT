<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/csrf_guard.php';

try {
    validateCsrfOrExit();

    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $fingerprint = trim((string)($input['fingerprint'] ?? ''));
    $deviceToken = trim((string)($input['deviceToken'] ?? ''));

    if ($email === '' || $fingerprint === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and device fingerprint are required.']);
        exit;
    }

    // Refuse to revoke the device currently in use.
    if ($deviceToken !== '') {
        require_once __DIR__ . '/_device_auth_helpers.php';
        $currentFingerprint = computeDeviceFingerprint($email, $deviceToken);
        if ($currentFingerprint !== '' && hash_equals($currentFingerprint, $fingerprint)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot revoke the device you are currently using.']);
            exit;
        }
    }

    $deleted = DB::table('trusted_devices')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->where('fingerprint', $fingerprint)
        ->delete();

    // Also clear any pending verification tokens for the revoked device.
    DB::table('login_verification_tokens')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->where('fingerprint', $fingerprint)
        ->delete();

    echo json_encode(['success' => true, 'revoked' => (int)$deleted]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to revoke trusted device', 'details' => $error->getMessage()]);
}
