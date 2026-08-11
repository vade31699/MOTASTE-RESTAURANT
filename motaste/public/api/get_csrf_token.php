<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

require_once __DIR__ . '/csrf_guard.php';

try {
    $token = getOrCreateCsrfToken();
    echo json_encode([
        'success' => true,
        'csrfToken' => $token,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to generate CSRF token',
    ]);
}
