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
require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();

require_once __DIR__ . '/_retention_helpers.php';

use Illuminate\Support\Facades\DB;

$input = json_decode(file_get_contents('php://input'), true);
$batchId = isset($input['batchId']) ? (int)$input['batchId'] : 0;
$confirmed = !empty($input['confirmed']);

if ($batchId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'batchId is required']);
    exit;
}
if (!$confirmed) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Confirmation is required to permanently delete archived records']);
    exit;
}

try {
    $batch = DB::table('data_retention_batches')->where('id', $batchId)->first();
    if (!$batch) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Retention batch not found']);
        exit;
    }

    $result = clearRetentionBatch($batchId);

    echo json_encode([
        'success' => true,
        'deleted' => $result['deleted'] ?? 0,
        'batchId' => $batchId,
        'status' => 'cleared',
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to clear retention batch']);
}
