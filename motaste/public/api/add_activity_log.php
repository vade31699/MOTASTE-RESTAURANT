<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function ensureOrderLogsTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

try {
    ensureOrderLogsTable();

    DB::table('order_activity_logs')->insert([
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'action' => $action,
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => $summary !== '' ? $summary : null,
        'details' => $details !== null
            ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save activity log', 'details' => $error->getMessage()]);
}