<?php
/**
 * Admin-only report of security-relevant configuration problems.
 *
 * Several protections fail closed when unconfigured (see authenticate_staff.php
 * and _security_config_helpers.php), which turns a missing environment variable
 * into logins refused with no visible cause. This endpoint is what lets the
 * dashboard show that reason to the one person who can fix it.
 *
 * Admin-gated on purpose: the response names which protections are degraded, so
 * it must never be readable by a non-admin or an anonymous caller — that would
 * hand an attacker a map of where the defences are thin.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
// 401 when the session is gone (the client must log in again), 403 when a
// signed-in non-admin reaches an admin-only read.
requireAdminAuthOrExit();

require_once __DIR__ . '/_security_config_helpers.php';

try {
    echo json_encode([
        'success' => true,
        'warnings' => collectSecurityConfigWarnings(),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load security configuration']);
}
