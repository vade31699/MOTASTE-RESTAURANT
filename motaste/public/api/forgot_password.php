<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = strtolower(trim((string)($input['email'] ?? '')));

    if ($email === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit;
    }

    // Rate limit: prevent abuse by limiting requests per email
    $rateLimitKey = 'forgot_password:' . md5($email);
    $cached = cache($rateLimitKey);
    if ($cached !== null && (int)$cached > 3) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
        exit;
    }

    // Use Laravel's built-in password broker to send reset link
    $status = Password::sendResetLink(['email' => $email]);

    if ($status == Password::RESET_LINK_SENT) {
        // Increment rate limit counter
        $count = (int)($cached ?? 0) + 1;
        cache([$rateLimitKey => $count], 60);

        echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a password reset link has been sent.']);
    } else {
        // Don't reveal whether the email exists or not (security best practice)
        echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a password reset link has been sent.']);
    }
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
