<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_device_auth_helpers.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $code = trim((string)($input['code'] ?? ''));
    $deviceToken = trim((string)($input['deviceToken'] ?? ''));

    if ($email === '' || $password === '' || $code === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email, password, and verification code are required.']);
        exit;
    }

    // Re-confirm the credentials on this step before trusting the device.
    $staffRow = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (!$staffRow || !isset($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    $role = trim((string)($staffRow->role ?? ''));
    $fingerprint = computeDeviceFingerprint($email, $deviceToken);

    if (!verifyDeviceLoginCode($email, $fingerprint, $code)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code']);
        exit;
    }

    // Code confirmed: trust this device for future logins and grant the session.
    markTrustedDeviceSeen($email, $fingerprint);

    $inviteConfirmed = true;
    if (in_array($role, ['Cashier', 'Inventory Manager'], true)) {
        $token = DB::table('staff_invite_tokens')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereRaw('LOWER(role) = ?', [strtolower($role)])
            ->first();

        if ($token) {
            $inviteConfirmed = false;
        }
    }

    // Persist a server-side session with a 30-day cookie (same behavior as a
    // normal login) so staff-only endpoints recognize this device.
    ensureStaffAuthSession();

    // Regenerate the session ID, then issue a fresh stateless CSRF token
    // bound to the NEW session ID. (Tokens are HMAC-signed and
    // self-contained, so nothing needs carrying across the regeneration.)
    session_regenerate_id(true);
    $_SESSION['staff'] = [
        'role' => $role,
        'email' => strtolower(trim((string)($staffRow->email ?? ''))),
        'name' => trim((string)($staffRow->full_name ?? '')),
        'logged_in_at' => now()->toDateTimeString()
    ];

    // Record the successful (device-verified) login in the credentials audit trail.
    recordStaffLoginHistory($email, $role, (string)($staffRow->full_name ?? ''));

    try {
        DB::table('order_activity_logs')->insert([
            'order_id' => null,
            'order_number' => null,
            'action' => 'device_login_verified',
            'actor_role' => $role,
            'actor_email' => strtolower(trim((string)($staffRow->email ?? ''))),
            'summary' => 'New device verified and added to trusted devices',
            'details' => json_encode([
                'device_label' => resolveDeviceLabel(),
                'device_token' => $deviceToken,
                'ip_address' => resolveClientIpAddress(),
                'verified_at' => now()->toDateTimeString(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $logError) {
        // Auditing must never block the login response.
    }

    $sessionToken = issueStaffSessionToken($email, $role);

    $freshCsrf = function_exists('getOrCreateCsrfToken') ? getOrCreateCsrfToken() : '';

    echo json_encode([
        'success' => true,
        'role' => $role,
        'email' => strtolower(trim((string)($staffRow->email ?? ''))),
        'name' => trim((string)($staffRow->full_name ?? '')),
        'inviteConfirmed' => $inviteConfirmed,
        'deviceVerified' => true,
        'sessionToken' => $sessionToken,
        'csrfToken' => $freshCsrf
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to verify device login', 'details' => $error->getMessage()]);
}
