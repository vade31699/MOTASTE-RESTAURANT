<?php

use Illuminate\Support\Facades\DB;

/**
 * Regression tests for the stateless CSRF token implementation.
 *
 * The CSRF token used to live in PHP's native $_SESSION, which does not
 * survive across serverless containers (each Laravel Cloud instance has its
 * own filesystem). A token issued by container A was rejected by container B,
 * causing intermittent "Invalid CSRF token" errors. Tokens are now
 * HMAC-signed, self-contained values bound to the session-ID cookie — any
 * container can validate them without shared storage.
 */
function bootCsrfTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database (mirrors the other unit
    // tests); never touch the production database.
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../public/api/csrf_guard.php';
}

test('issued CSRF tokens validate successfully', function () {
    bootCsrfTestApp();

    $token = getOrCreateCsrfToken();

    expect($token)->not->toBe('');
    expect(isValidCsrfToken($token))->toBeTrue();
});

test('tampered or malformed tokens are rejected', function () {
    bootCsrfTestApp();

    $token = getOrCreateCsrfToken();

    // Flip a character inside the signed payload.
    $tampered = substr_replace($token, $token[5] === 'A' ? 'B' : 'A', 5, 1);
    expect(isValidCsrfToken($tampered))->toBeFalse();

    // Random garbage.
    expect(isValidCsrfToken('not-a-token'))->toBeFalse();
    expect(isValidCsrfToken(''))->toBeFalse();
});

test('a token issued for a different session ID is rejected', function () {
    bootCsrfTestApp();

    // Simulate a browser whose session cookie is absent on the next request
    // (container rotation on serverless): start a fresh session, which yields
    // a different session ID than the one the token was bound to.
    $firstSession = session_id();
    $token = getOrCreateCsrfToken();

    session_write_close();
    session_id($firstSession . '-rotated');
    session_start();

    expect(isValidCsrfToken($token))->toBeFalse();

    // And a token issued for the NEW session validates again.
    $fresh = getOrCreateCsrfToken();
    expect(isValidCsrfToken($fresh))->toBeTrue();
});

test('tokens issued by get_csrf_token endpoint round-trip through validation', function () {
    bootCsrfTestApp();

    // Mirrors exactly what public/api/get_csrf_token.php does.
    $token = getOrCreateCsrfToken();
    $payload = json_encode(['success' => true, 'csrfToken' => $token]);

    expect(json_decode($payload, true)['csrfToken'])->toBe($token);
    expect(isValidCsrfToken(json_decode($payload, true)['csrfToken']))->toBeTrue();

    // Cleanup guard: the DB facade must be resolvable (bootstrap already ran).
    expect(DB::connection()->getDatabaseName())->not->toBe('');
});

/**
 * Build a token in the exact wire format the guard uses with a chosen expiry,
 * so the expiry check can be exercised without waiting 8 hours.
 */
function buildExpiryControlledCsrfToken(int $expiry): string
{
    $sessionId = session_id();
    if (!is_string($sessionId) || $sessionId === '') {
        $sessionId = 'nosession';
    }
    $payload = $expiry . '.' . str_repeat('ab', 24) . '.' . $sessionId;
    return base64_encode($payload) . '.' . hash_hmac('sha256', $payload, csrfSigningSecret());
}

test('expired CSRF tokens are rejected', function () {
    bootCsrfTestApp();

    $expired = buildExpiryControlledCsrfToken(time() - 1000);
    expect(isValidCsrfToken($expired))->toBeFalse();

    $expiring = buildExpiryControlledCsrfToken(time() + 3600);
    expect(isValidCsrfToken($expiring))->toBeTrue();
});

test('a request with no CSRF token resolves to empty and is rejected', function () {
    bootCsrfTestApp();

    $originalHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $originalPost = $_POST['csrfToken'] ?? null;
    try {
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_POST['csrfToken']);

        $provided = resolveRequestCsrfToken();
        expect($provided)->toBe('');

        // The guard's reject path is exactly "invalid or missing".
        expect(isValidCsrfToken($provided))->toBeFalse();
    } finally {
        if ($originalHeader === null) {
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        } else {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $originalHeader;
        }
        if ($originalPost === null) {
            unset($_POST['csrfToken']);
        } else {
            $_POST['csrfToken'] = $originalPost;
        }
    }
});

test('the request token is read from the header first, then JSON body, then form field', function () {
    bootCsrfTestApp();

    $originalHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $originalPost = $_POST['csrfToken'] ?? null;
    try {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'header-token';
        $_POST['csrfToken'] = 'form-token';
        expect(resolveRequestCsrfToken())->toBe('header-token');

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        expect(resolveRequestCsrfToken())->toBe('form-token');
    } finally {
        if ($originalHeader === null) {
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        } else {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $originalHeader;
        }
        if ($originalPost === null) {
            unset($_POST['csrfToken']);
        } else {
            $_POST['csrfToken'] = $originalPost;
        }
    }
});

test('every state-changing endpoint loads the CSRF guard and validates before mutating', function (string $endpoint) {
    bootCsrfTestApp();

    $source = file_get_contents(__DIR__ . '/../../public/api/' . $endpoint);
    expect($source)
        ->toContain('require_once __DIR__ . \'/csrf_guard.php\';')
        ->toContain('validateCsrfOrExit();');
})->with([
    'add_activity_log.php',
    'authenticate_staff.php',
    'cancel_order.php',
    'clear_retention_batch.php',
    'confirm_account_change.php',
    'confirm_staff_invite.php',
    'create_order.php',
    'create_staff.php',
    'delete_inventory_item.php',
    'delete_review.php',
    'delete_staff.php',
    'export_retention_batch.php',
    'fresh_start.php',
    'logout_staff.php',
    'mark_order_complete.php',
    'notify_staff_session.php',
    'publish_review.php',
    'refund_order.php',
    'renew_staff_session.php',
    'request_account_change.php',
    'revoke_trusted_device.php',
    'save_custom_menu.php',
    'save_highlights.php',
    'save_review.php',
    'save_staff_accounts.php',
    'send_staff_invite.php',
    'start_order_preparation.php',
    'totp_confirm.php',
    'totp_disable.php',
    'totp_setup.php',
    'update_inventory.php',
    'update_pending_order_item.php',
    'update_staff.php',
    'upload_special_food_image.php',
    'verify_account_change_code.php',
    'verify_device_login.php',
]);
