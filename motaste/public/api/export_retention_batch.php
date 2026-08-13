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

use Illuminate\Support\Facades\DB;

$batchId = (int)($_GET['id'] ?? 0);
if ($batchId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'batch id is required']);
    exit;
}

try {
    $batch = DB::table('data_retention_batches')->where('id', $batchId)->first();
    if (!$batch) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Retention batch not found']);
        exit;
    }

    // Live rows in the batch window (already cleared -> empty export).
    $rows = fetchRetentionBatchRows(
        (string)$batch->batch_type,
        $batch->period_start,
        $batch->period_end
    );

    // Mark the batch as exported so the dashboard shows the admin already
    // grabbed a copy (export does not delete anything).
    DB::table('data_retention_batches')->where('id', $batchId)->update([
        'status' => 'exported',
        'exported_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode([
        'success' => true,
        'batch' => [
            'id' => (int)$batch->id,
            'batch_type' => (string)$batch->batch_type,
            'period_label' => (string)$batch->period_label,
            'period_start' => $batch->period_start,
            'period_end' => $batch->period_end,
            'record_count' => (int)$batch->record_count,
            'status' => 'exported',
        ],
        'headers' => getRetentionBatchHeaders((string)$batch->batch_type),
        'rows' => $rows,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to export retention batch']);
}
