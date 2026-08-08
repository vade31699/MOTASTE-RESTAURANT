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

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $selectedRole = trim((string)($input['role'] ?? ''));
    $deviceToken = trim((string)($input['deviceToken'] ?? ''));

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    $staffRow = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (!$staffRow || !isset($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
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

        if (!$emailResult['success']) {
            $response['warning'] = 'Verification email could not be delivered: '
                . ($emailResult['error'] ?? 'unknown mail error')
                . ' Check the server logs for the code.';
            error_log('[MOTASTE device verification] code for ' . $email . ': ' . $code);
        }

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

    // Persist a server-side session so subsequent server endpoints can recognize the staff user.
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);
    $_SESSION['staff'] = [
        'role' => $role,
        'email' => strtolower(trim((string)($staffRow->email ?? ''))),
        'name' => trim((string)($staffRow->full_name ?? '')),
        'logged_in_at' => now()->toDateTimeString()
    ];

    echo json_encode([
        'success' => true,
        'role' => $role,
        'email' => strtolower(trim((string)($staffRow->email ?? ''))),
        'name' => trim((string)($staffRow->full_name ?? '')),
        'inviteConfirmed' => $inviteConfirmed,
        'deviceVerified' => true
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to authenticate staff account', 'details' => $error->getMessage()]);
}
