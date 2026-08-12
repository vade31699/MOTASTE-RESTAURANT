<?php

/**
 * Stateless CSRF tokens (HMAC-signed, expiring).
 *
 * WHY: the previous implementation stored the token inside PHP's native
 * $_SESSION. On serverless platforms (Laravel Cloud) every request can be
 * served by a different container with its own filesystem, so a token written
 * to a session file on container A was invisible to a request routed to
 * container B: validation silently generated a fresh token, compared it to
 * the one the browser sent, and rejected it with "Invalid CSRF token".
 *
 * Tokens are now self-contained: base64(payload).signature where payload is
 * "expiry.nonce.sessionId". The signature is an HMAC-SHA256 keyed with the
 * application key (the same value on every container), so ANY container can
 * validate a token without shared storage. The token is still bound to the
 * browser's session-ID cookie value (stable across containers) and expires
 * after CSRF_TOKEN_TTL_SECONDS, so a stolen token cannot be reused forever.
 */

/** How long a signed CSRF token stays valid, in seconds. */
const CSRF_TOKEN_TTL_SECONDS = 8 * 60 * 60;

function ensureSessionForCsrf(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Shared signing secret. Prefers the Laravel application key (every API
 * endpoint bootstraps Laravel, so config() is available and APP_KEY is the
 * same value on every serverless container). Fails closed when unavailable.
 */
function csrfSigningSecret(): string
{
    $key = '';

    if (function_exists('config')) {
        try {
            $key = (string) config('app.key');
        } catch (Throwable $error) {
            $key = '';
        }
    }

    if ($key === '') {
        $envKey = getenv('APP_KEY');
        if (is_string($envKey) && $envKey !== '') {
            $key = $envKey;
        }
    }

    if ($key === '') {
        return '';
    }

    if (strpos($key, 'base64:') === 0) {
        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded !== false && $decoded !== '') {
            $key = $decoded;
        }
    }

    return 'motaste-csrf-v2|' . $key;
}

/**
 * Build a new signed, expiring CSRF token. No server-side state is written.
 */
function buildSignedCsrfToken(): string
{
    $secret = csrfSigningSecret();
    if ($secret === '') {
        return '';
    }

    ensureSessionForCsrf();

    $nonce = bin2hex(random_bytes(24));
    $expiry = time() + CSRF_TOKEN_TTL_SECONDS;
    $sessionId = session_id();
    if (!is_string($sessionId) || $sessionId === '') {
        $sessionId = 'nosession';
    }

    $payload = $expiry . '.' . $nonce . '.' . $sessionId;
    $signature = hash_hmac('sha256', $payload, $secret);

    return base64_encode($payload) . '.' . $signature;
}

/**
 * Verify a token's signature, expiry window, and session binding.
 */
function isValidCsrfToken(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    $decoded = base64_decode($parts[0], true);
    if ($decoded === false) {
        return false;
    }

    $payloadParts = explode('.', $decoded);
    if (count($payloadParts) !== 3) {
        return false;
    }

    [$expiry, $nonce, $sessionId] = $payloadParts;

    if (!ctype_digit($expiry) || !preg_match('/^[0-9a-f]{48}$/', $nonce)) {
        return false;
    }

    // The signature proves the payload was issued by this application, so
    // tampered or attacker-forged tokens are rejected before anything else.
    $secret = csrfSigningSecret();
    if ($secret === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $decoded, $secret);
    if (!hash_equals($expected, $parts[1])) {
        return false;
    }

    // The token must not be expired.
    if ((int) $expiry < time()) {
        return false;
    }

    // And it must belong to the same browser session (session-ID cookie value).
    ensureSessionForCsrf();
    $currentSession = session_id();
    if (!is_string($currentSession)) {
        $currentSession = '';
    }
    if ($sessionId === 'nosession') {
        // A token minted while no session existed must only validate while no
        // session exists either; never accept it against a real session.
        if ($currentSession !== '') {
            return false;
        }
    } elseif ($sessionId !== $currentSession) {
        return false;
    }

    return true;
}

/**
 * Return a fresh CSRF token for the client. Kept as the public entry point so
 * callers (get_csrf_token.php, login/renewal responses) need no changes.
 *
 * Note: the name is historical — tokens are stateless now, so this always
 * mints a NEW token rather than returning an existing one.
 */
function getOrCreateCsrfToken(): string
{
    return buildSignedCsrfToken();
}

function resolveRequestCsrfToken(): string
{
    $headerToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($headerToken !== '') {
        return $headerToken;
    }

    $bodyRaw = file_get_contents('php://input');
    if ($bodyRaw !== false && $bodyRaw !== '') {
        $decoded = json_decode($bodyRaw, true);
        if (is_array($decoded)) {
            $bodyToken = trim((string)($decoded['csrfToken'] ?? ''));
            if ($bodyToken !== '') {
                return $bodyToken;
            }
        }
    }

    $postToken = trim((string)($_POST['csrfToken'] ?? ''));
    return $postToken;
}

function validateCsrfOrExit(): void
{
    $provided = resolveRequestCsrfToken();

    $valid = false;
    if ($provided !== '') {
        try {
            $valid = isValidCsrfToken($provided);
        } catch (Throwable $error) {
            $valid = false;
        }
    }

    if (!$valid) {
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid CSRF token. Please refresh and try again.',
        ]);
        exit;
    }
}
