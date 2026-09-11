<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
    $turnstileToken = trim((string)($input['cf-turnstile-response'] ?? ''));
    $deviceToken = trim((string)($input['deviceToken'] ?? ''));

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    // Brute-force protection: lock the account after repeated failures.
    // The message is deliberately vague — never reveal the lockout duration
    // or remaining attempts to a possible attacker.
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

    // CAPTCHA enforcement: required after 2 failed attempts (per account or
    // per IP, within the lockout window) or when the login pattern is
    // suspicious (new IP for an established account, distributed failures,
    // or email rotation from this IP) — even on the very first submit.
    $ipRecentFails = 0;
    $accountRecentFails = 0;
    try {
        $ipRecentFails = (int) DB::table('login_attempts')
            ->where('ip_address', $clientIp)
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subMinutes(STAFF_LOGIN_LOCKOUT_MINUTES)->toDateTimeString())
            ->count();

        $accountRecentFails = (int) DB::table('login_attempts')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subMinutes(STAFF_LOGIN_LOCKOUT_MINUTES)->toDateTimeString())
            ->count();
    } catch (Throwable $e) {
        // Best effort.
    }

    $captchaRequired = $ipRecentFails >= STAFF_LOGIN_CAPTCHA_THRESHOLD
        || $accountRecentFails >= STAFF_LOGIN_CAPTCHA_THRESHOLD;

    if (!$captchaRequired) {
        $captchaRequired = isSuspiciousLoginAttempt($email, $clientIp);
    }

    if ($captchaRequired) {
        // CAPTCHA is required: validate the Turnstile token.
        $turnstileSecret = env('TURNSTILE_SECRET_KEY', '');
        if ($turnstileSecret === '') {
            // CAPTCHA provider not configured — skip validation but still
            // require the widget on the client (fail-open for misconfiguration).
        } elseif ($turnstileToken === '') {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'Please complete the CAPTCHA verification.',
                'needsCaptcha' => true,
            ]);
            exit;
        } else {
            $turnstileResult = verifyTurnstileToken($turnstileToken, $turnstileSecret, $clientIp);
            if (!$turnstileResult) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'CAPTCHA verification failed. Please try again.',
                    'needsCaptcha' => true,
                ]);
                exit;
            }
        }
    }

    $staffRow = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (!$staffRow || !isset($staffRow->password_hash) || !Hash::check($password, $staffRow->password_hash)) {
        recordLoginAttempt($email, false);
        http_response_code(401);

        // Generic message: never reveal whether the account exists or how
        // many attempts remain before lockout (the lockout still applies —
        // the user just finds out via the vague 429 above instead of a count).
        echo json_encode([
            'success' => false,
            'error' => 'Invalid username or Password.',
        ]);
        exit;
    }

    $role = trim((string)($staffRow->role ?? ''));
    if ($selectedRole !== '' && $selectedRole !== $role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid role for this account']);
        exit;
    }

    // ---- Login verification (required for every login) -------------------
    // For maximum security, EVERY staff login — Admin, Cashier, and Inventory
    // Manager — must confirm a verification code that is emailed to the
    // account's address before a session is created. A device is remembered
    // only as a record of verified logins; it never bypasses this code.
    $fingerprint = computeDeviceFingerprint($email, $deviceToken);

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
            'message' => 'A verification code was already sent to your email — check your inbox.',
            'deviceToken' => $deviceToken,
        ]);
        exit;
    }

    $code = createDeviceLoginCode($email, $fingerprint);
    $deviceLabel = resolveDeviceLabel();
    $occurredAt = now()->toDateTimeString();

    $emailBody = "MOTASTE login verification\n\n" .
        "A login was attempted for this account. For security, a verification\n" .
        "code is required for every login.\n\n" .
        "Verification code: {$code}\n" .
        "Expires: " . now()->addMinutes(3)->toDateTimeString() . "\n\n" .
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
            'action' => 'staff_login_verification_sent',
            'actor_role' => $role,
            'actor_email' => $email,
            'summary' => 'Verification code emailed for staff login',
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
        'message' => 'A verification code was sent to your email.',
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
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to authenticate staff account']);
}
