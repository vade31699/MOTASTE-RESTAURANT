<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}


require_once __DIR__ . '/_email_auth_helpers.php';

try {
    $accounts = loadStaffAccountsSnapshot();

    $admin = getAdminAccount($accounts);
    if (!$admin) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Admin account is missing']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'credentials' => [
            'email' => $admin['email'],
        ],
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load admin credentials']);
}
