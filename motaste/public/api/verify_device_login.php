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

    // Brute-force protection: apply the same per-account lockout as the
    // login endpoint so verification codes cannot be mass-guessed.
    if (isLoginRateLimited($email)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Please Try Again Later.',
            'rateLimited' => true,
        ]);
        exit;
    }

    // IP-based brute-force protection: prevent an attacker from rotating
    // emails to bypass per-account lockout.
    $clientIp = resolveClientIpAddress();
    if (isLoginIpRateLimited($clientIp)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Please Try Again Later.',
            'rateLimited' => true,
        ]);
        exit;
    }

    // Re-confirm the credentials on this step before trusting the device.
    $staffRow = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (!$staffRow || !isset($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
        // Count the failure so this endpoint feeds the same per-account and
        // per-IP lockout budgets as authenticate_staff.php — otherwise the
        // isLoginRateLimited() checks above could never trip from here.
        recordLoginAttempt($email, false);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid username or Password.']);
        exit;
    }

    $role = trim((string)($staffRow->role ?? ''));
    $fingerprint = computeDeviceFingerprint($email, $deviceToken);

    if (!verifyDeviceLoginCode($email, $fingerprint, $code)) {
        // A wrong code is a brute-force attempt against the emailed 6-digit
        // challenge: count it toward lockout too (the token row itself also
        // self-destructs after 5 failed attempts via verifyDeviceLoginCode()).
        recordLoginAttempt($email, false);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code']);
        exit;
    }

    // Code confirmed: clear the failure counters, record this device as a
    // verified login (informational only — every login still requires a fresh
    // emailed code), and grant the session.
    recordLoginAttempt($email, true);
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
            'summary' => 'Staff login verified with emailed code',
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
    echo json_encode(['success' => false, 'error' => 'Unable to verify device login']);
}
