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
    DB::statement("CREATE TABLE IF NOT EXISTS order_activity_logs (
        id BIGSERIAL PRIMARY KEY,
        order_id BIGINT NULL,
        order_number VARCHAR(191) NULL,
        action VARCHAR(100) NOT NULL,
        actor_role VARCHAR(100) NULL,
        actor_email VARCHAR(191) NULL,
        summary TEXT NULL,
        details TEXT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
}

$input = json_decode(file_get_contents('php://input'), true);
$action = trim((string)($input['action'] ?? ''));
$summary = trim((string)($input['summary'] ?? ''));
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));
$orderId = isset($input['orderId']) ? (int)$input['orderId'] : null;
$orderNumber = isset($input['orderNumber']) ? trim((string)$input['orderNumber']) : null;
$details = $input['details'] ?? null;

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action is required']);
    exit;
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