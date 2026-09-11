<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
$actor = requireStaffAuth();
if (!$actor) {
    abortStaffAuthRequired();
}


use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/csrf_guard.php';

try {
    validateCsrfOrExit();

    $input = json_decode(file_get_contents('php://input'), true);
    $body = is_array($input) ? $input : [];
    $deviceId = (int)($body['id'] ?? 0);
    $fingerprint = trim((string)($body['fingerprint'] ?? ''));
    $deviceToken = trim((string)($body['deviceToken'] ?? ''));

    if ($deviceId === 0 && $fingerprint === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Device id or fingerprint is required.']);
        exit;
    }

    // Resolve the device row by id (preferred) or by fingerprint, which is
    // unique in trusted_devices. The list endpoint masks emails for DPA
    // (d***@gmail.com), so a client-supplied email can never match a row —
    // authorization is checked against the row's own email instead.
    $device = $deviceId > 0
        ? DB::table('trusted_devices')->where('id', $deviceId)->first()
        : DB::table('trusted_devices')->where('fingerprint', $fingerprint)->first();

    if (!$device) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Trusted device not found. It may already have been removed.']);
        exit;
    }

    // Non-admins may only revoke their own devices; admins may revoke any.
    $rowEmail = strtolower(trim((string)$device->email));
    if (strtolower(trim((string)($actor['role'] ?? ''))) !== 'admin'
        && $rowEmail !== strtolower(trim((string)($actor['email'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only remove devices for your own account.']);
        exit;
    }

    // Refuse to revoke the device currently in use.
    if ($deviceToken !== '') {
        require_once __DIR__ . '/_device_auth_helpers.php';
        $currentFingerprint = computeDeviceFingerprint($rowEmail, $deviceToken);
        if ($currentFingerprint !== '' && hash_equals($currentFingerprint, (string)$device->fingerprint)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot revoke the device you are currently using.']);
            exit;
        }
    }

    $deleted = DB::table('trusted_devices')->where('id', $device->id)->delete();

    // Also clear any pending verification tokens for the revoked device.
    DB::table('login_verification_tokens')
        ->whereRaw('LOWER(email) = ?', [$rowEmail])
        ->where('fingerprint', $device->fingerprint)
        ->delete();

    echo json_encode(['success' => true, 'revoked' => (int)$deleted]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to revoke trusted device']);
}
