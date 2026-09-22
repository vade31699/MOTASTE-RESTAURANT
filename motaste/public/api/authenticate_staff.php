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
require_once __DIR__ . '/_totp_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

// CSRF: login issues verification codes / TOTP challenges (login-CSRF vector)
// and writes login_attempts + login_verification_tokens. The client attaches
// the signed token via withCsrfHeaders(); a missing/invalid token is rejected
// here, before any rate-limit counter, comparison, or email is touched.
validateCsrfOrExit();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $selectedRole = is_string($input['role'] ?? null) ? trim($input['role']) : '';
    // Which portal the login came from: /admin accepts only the Admin account,
    // /staff accepts both admin and staff. The client sends this explicitly;
    // a missing value falls back to the (more permissive) staff surface.
    $surface = is_string($input['surface'] ?? null) ? strtolower(trim($input['surface'])) : 'staff';
    $recaptchaToken = is_string($input['recaptcha-token'] ?? null) ? trim($input['recaptcha-token']) : '';
    $deviceToken = is_string($input['deviceToken'] ?? null) ? trim($input['deviceToken']) : '';

    if ($email === '' || $password === '' || trim($password) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    // Format/length guards before the email touches any lookup or audit table.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'The email address is invalid.']);
        exit;
    }
    if (mb_strlen($deviceToken) > 256) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'The device token is invalid.']);
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

    // CAPTCHA enforcement: required after 3 failed attempts (per account or
    // per IP, within the lockout window) or when the login pattern is
    // suspicious (new IP for an established account, distributed failures,
    // or email rotation from this IP) — even on the very first submit.
    $ipRecentFails = 0;
    $accountRecentFails = 0;
    try {
        $ipRecentFails = (int) DB::table('login_attempts')
            ->where('ip_address', $clientIp)
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subMinutes(staffLoginLockoutMinutes())->toDateTimeString())
            ->count();

        $accountRecentFails = (int) DB::table('login_attempts')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subMinutes(staffLoginLockoutMinutes())->toDateTimeString())
            ->count();
    } catch (Throwable $e) {
        // Best effort.
    }

    // Threshold is deployment-tunable via STAFF_LOGIN_CAPTCHA_THRESHOLD
    // (defaults to STAFF_LOGIN_CAPTCHA_THRESHOLD_DEFAULT).
    $captchaThreshold = staffLoginCaptchaThreshold();
    $captchaRequired = $ipRecentFails >= $captchaThreshold
        || $accountRecentFails >= $captchaThreshold;

    if (!$captchaRequired) {
        $captchaRequired = isSuspiciousLoginAttempt($email, $clientIp);
    }

    if ($captchaRequired) {
        // CAPTCHA is required: validate the reCAPTCHA v2 token.
        $recaptchaSecret = (string) env('RECAPTCHA_V2_SECRET_KEY', '');

        if ($recaptchaSecret === '') {
            // FAIL CLOSED. The gate is armed (this account/IP passed the failure
            // threshold, or the login pattern is suspicious) but the verifier
            // has no secret, so NO token can be validated — not even a genuine
            // one from a real user. Proceeding here would silently drop the
            // gate to a no-op at the exact moment it is doing its job, so the
            // login is refused instead and the misconfiguration is logged.
            //
            // Scoped to the armed case on purpose: refusing every login while
            // the key is unset would turn a missing env var into a total login
            // outage. This closes the security hole without that blast radius.
            error_log('[MOTASTE] RECAPTCHA_V2_SECRET_KEY is not configured; refusing CAPTCHA-gated staff login (fail closed). Set the key to restore logins from rate-limited accounts/IPs.');
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'Login is temporarily unavailable. Please contact the administrator.',
                'captchaUnavailable' => true,
            ]);
            exit;
        }

        if ($recaptchaToken === '') {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'Please complete the CAPTCHA verification.',
                'needsCaptcha' => true,
            ]);
            exit;
        }

        $recaptchaResult = verifyRecaptchaTokenDetailed($recaptchaToken, $recaptchaSecret, $clientIp);

        // A transport failure (Google unreachable, or its certificate not
        // trusted — e.g. a PHP with no CA store) and a misconfigured secret are
        // NOT the visitor's fault and cannot be fixed by solving the checkbox
        // again. Reporting them as "CAPTCHA verification failed" sent users into
        // an endless retry loop while the real cause stayed invisible, so they
        // fail closed with the same 503 + captchaUnavailable the unconfigured
        // case uses, and the detail is logged by the verifier.
        if ($recaptchaResult['reason'] === RECAPTCHA_REASON_TRANSPORT) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'CAPTCHA verification is temporarily unavailable. Please contact the administrator.',
                'captchaUnavailable' => true,
            ]);
            exit;
        }

        if ($recaptchaResult['reason'] === RECAPTCHA_REASON_MISCONFIGURED) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'Login is temporarily unavailable. Please contact the administrator.',
                'captchaUnavailable' => true,
            ]);
            exit;
        }

        // Left: Google answered and rejected the token. That IS the visitor's
        // problem — a stale, replayed, or wrong-challenge token — so invite a
        // retry with a fresh challenge.
        if ($recaptchaResult['reason'] !== RECAPTCHA_REASON_OK) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'CAPTCHA verification failed. Please try again.',
                'needsCaptcha' => true,
            ]);
            exit;
        }
    }

    // Resolve the account across the `staff` and `admins` tables, honoring the
    // login surface: the admin portal never accepts a staff-table account, so
    // only the Admin address can sign in there. Staff-table accounts that try
    // /admin get the same generic invalid-credentials answer as any other
    // miss — the portal boundary must not reveal whether an address exists.
    $staffRow = findLoginAccountForSurface($email, $surface);

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

    // ---- Authenticator-app (TOTP) factor --------------------------------
    // Accounts with 2FA enabled skip the emailed code entirely: the only
    // allowed second factor is a live code from the authenticator app. This
    // early exit also keeps the emailed-code issuance below (rate limits,
    // cooloffs, inbox flooding) irrelevant for those accounts.
    if (totpEnabledFor($email)) {
        echo json_encode([
            'success' => false,
            'needsTotp' => true,
            'email' => $email,
            'role' => $role,
            'message' => 'Enter the 6-digit code from your authenticator app.',
            'deviceToken' => $deviceToken,
        ]);
        exit;
    }

    // ---- Login verification (required for every login) -------------------
    // For maximum security, EVERY staff login — Admin, Cashier, and Inventory
    // Manager — must confirm a verification code that is emailed to the
    // account's address before a session is created. A device is remembered
    // only as a record of verified logins; it never bypasses this code.
    $fingerprint = computeDeviceFingerprint($email, $deviceToken);

    // Rate-limit code issuance: never send more than one code per 30 seconds
    // per account. Reuse a code created in the last 60 seconds when the window
    // is open, otherwise block rapid re-issuance that would flood the inbox.
    $existingToken = DB::table('login_verification_tokens')
        ->where('email', $email)
        ->where('fingerprint', $fingerprint)
        ->orderBy('id', 'desc')
        ->first();

    // Expired tokens are useless — clean them up before checking the cooloff.
    if ($existingToken && now()->greaterThan($existingToken->expires_at)) {
        DB::table('login_verification_tokens')
            ->where('id', $existingToken->id)
            ->delete();
        $existingToken = null;
    }

    $codeAlreadySent = $existingToken
        && now()->lessThan($existingToken->expires_at)
        && now()->diffInSeconds($existingToken->created_at) < 60;

    $codeIssuanceCooloff = false;
    if (!$codeAlreadySent && $existingToken && now()->diffInSeconds($existingToken->created_at) < 30) {
        // The previous code was created too recently to send another one.
        $codeIssuanceCooloff = true;
    }

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

    if ($codeIssuanceCooloff) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Please wait before requesting a new verification code.',
            'rateLimited' => true,
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

    if (empty($emailResult['success'])) {
        // SMTP may be misconfigured or the provider may have rejected the
        // message. The failure is reported generically so the reason (and any
        // mailer detail) is not exposed to the browser. Codes are never
        // written to a production log, so there is nothing to point the user
        // at — fail closed and let them retry or contact the administrator.
        $response['warning'] = 'The verification code could not be delivered to your email. Please try again later or contact the administrator.';
    }
    // NOTE: codes are never logged in production; sendSystemEmail() fails
    // closed rather than falling back to a file-based mailer.

    echo json_encode($response);
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to authenticate staff account']);
}
