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
require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $selectedRole = trim((string)($input['role'] ?? ''));
    $deviceToken = trim((string)($input['deviceToken'] ?? ''));
    $silentRefresh = !empty($input['silentRefresh']);

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    // Brute-force protection: lock the account after repeated failures.
    if (isLoginRateLimited($email)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Too many failed login attempts. Please try again in 15 minutes.',
            'rateLimited' => true,
        ]);
        exit;
    }

    $staffRow = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (!$staffRow || !isset($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
        recordLoginAttempt($email, false);
        http_response_code(401);

        // Tell the staff member how many attempts remain before lockout.
        $failedCount = 0;
        try {
            $failedCount = (int)DB::table('login_attempts')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('success', false)
                ->count();
        } catch (Throwable $countError) {
            // Best effort.
        }
        $remaining = max(0, STAFF_LOGIN_MAX_ATTEMPTS - $failedCount);

        echo json_encode([
            'success' => false,
            'error' => $remaining > 0
                ? "Invalid credentials. {$remaining} attempt(s) left before your account is locked for 15 minutes."
                : 'Invalid credentials. Your account is now locked for 15 minutes.',
            'remainingAttempts' => $remaining,
        ]);
        exit;
    }

    $role = trim((string)($staffRow->role ?? ''));
    if ($selectedRole !== '' && $selectedRole !== $role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid role for this account']);
        exit;
    }

    // ---- Trusted device recognition -------------------------------------
    // Every account (Admin, Cashier, Inventory Manager) must log in from a
    // recognized device. Unrecognized devices are challenged with a code that
    // is emailed to the account's address before a session is created.
    $fingerprint = computeDeviceFingerprint($email, $deviceToken);

    if (!deviceIsTrusted($email, $fingerprint)) {
        // Rate-limit code issuance: reuse a code that was created in the last
        // 60 seconds instead of emailing a fresh one on every attempt.
        $existingToken = DB::table('login_verification_tokens')
            ->where('email', $email)
            ->where('fingerprint', $fingerprint)
            ->orderBy('id', 'desc')
            ->first();
        $codeAlreadySent = $existingToken
            && now()->lessThan($existingToken->expires_at)
            && now()->diffInSeconds($existingToken->created_at) < 60;

        if ($codeAlreadySent) {
            echo json_encode([
                'success' => false,
                'needsDeviceVerification' => true,
                'email' => $email,
                'role' => $role,
                'message' => 'New device detected. A verification code was already sent to your email — check your inbox.',
                'deviceToken' => $deviceToken,
            ]);
            exit;
        }

        $code = createDeviceLoginCode($email, $fingerprint);
        $deviceLabel = resolveDeviceLabel();
        $occurredAt = now()->toDateTimeString();

        $emailBody = "MOTASTE login verification\n\n" .
            "A login was attempted from a new device for this account.\n\n" .
            "Verification code: {$code}\n" .
            "Expires: " . now()->addMinutes(10)->toDateTimeString() . "\n\n" .
            "Device: {$deviceLabel}\n" .
            "IP Address: " . resolveClientIpAddress() . "\n" .
            "Date/Time: {$occurredAt}\n\n" .
            "Enter this code on the device where you are signing in.\n" .
            "If this was not you, change your password immediately.";

        $emailResult = sendSystemEmail($email, 'MOTASTE Login Verification Code', $emailBody);

        // Record the challenge for auditing (device events stay in order logs).
        try {
            DB::table('order_activity_logs')->insert([
                'order_id' => null,
                'order_number' => null,
                'action' => 'new_device_login_verification_sent',
                'actor_role' => $role,
                'actor_email' => $email,
                'summary' => 'Verification code emailed for login from an unrecognized device',
                'details' => json_encode([
                    'device_label' => $deviceLabel,
                    'device_token' => $deviceToken,
                    'ip_address' => resolveClientIpAddress(),
                    'email_delivered' => $emailResult['success'] ?? false,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $logError) {
            // Auditing must never block the verification response.
        }

        $response = [
            'success' => false,
            'needsDeviceVerification' => true,
            'email' => $email,
            'role' => $role,
            'message' => 'New device detected. A verification code was sent to your email.',
            'deviceToken' => $deviceToken,
        ];

        if (!empty($emailResult['warning'])) {
            // SMTP is not configured; the message (including the code) was
            // written to the server log as a fallback.
            $response['warning'] = $emailResult['warning']
                . ' The verification code was written to the server log.';
        } elseif (!$emailResult['success']) {
            $response['warning'] = 'Verification email could not be delivered: '
                . ($emailResult['error'] ?? 'unknown mail error')
                . ' Check the server logs for the code.';
        }
        // NOTE: when SMTP fails, sendSystemEmail() already falls back to
        // writing the code to the server log — never log the raw code again.

        echo json_encode($response);
        exit;
    }

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

    recordLoginAttempt($email, true);

    // Persist a server-side session (30-day cookie) so subsequent server
    // endpoints can recognize the staff user. Silent refreshes re-issue the
    // session without spamming the login history audit trail.
    ensureStaffAuthSession();

    // Regenerate the session ID so the pre-login session cannot be hijacked,
    // then issue a fresh stateless CSRF token bound to the NEW session ID.
    // (Tokens are HMAC-signed and self-contained, so nothing needs carrying
    // across the regeneration.)
    session_regenerate_id(true);
    $_SESSION['staff'] = [
        'role' => $role,
        'email' => strtolower(trim((string)($staffRow->email ?? ''))),
        'name' => trim((string)($staffRow->full_name ?? '')),
        'logged_in_at' => now()->toDateTimeString()
    ];

    // Record the successful login in the credentials audit trail (not for silent refresh).
    if (!$silentRefresh) {
        recordStaffLoginHistory($email, $role, (string)($staffRow->full_name ?? ''));
    }

    // Issue an opaque bearer token so the client can restore this session after
    // a browser restart WITHOUT persisting the plaintext password.
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
    echo json_encode(['success' => false, 'error' => 'Unable to authenticate staff account']);
}
