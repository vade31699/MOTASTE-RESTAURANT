<?php

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

function getOrCreateCsrfToken(): string
{
    ensureSessionForCsrf();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
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
    $expected = getOrCreateCsrfToken();
    $provided = resolveRequestCsrfToken();

    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid CSRF token. Please refresh and try again.',
        ]);
        exit;
    }
}
