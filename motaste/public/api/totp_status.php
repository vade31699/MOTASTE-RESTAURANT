<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_totp_helpers.php';

try {
    // Only the Admin inspects account settings for staff.
    requireAdminAuthOrExit();

    $input = json_decode(file_get_contents('php://input'), true);
    $targetEmail = strtolower(trim((string)($input['targetEmail'] ?? '')));
    if ($targetEmail === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Target account is required']);
        exit;
    }

    $row = getTotpSettingRow($targetEmail);

    echo json_encode([
        'success' => true,
        'enabled' => $row !== null && (int) $row->enabled === 1,
        'pending' => $row !== null && (int) $row->enabled === 0,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load two-factor status']);
}