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

require_once __DIR__ . '/_retention_helpers.php';

try {
    $batches = getRetentionBatchesForAdmin();
    echo json_encode(['success' => true, 'batches' => $batches]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load retention batches']);
}
