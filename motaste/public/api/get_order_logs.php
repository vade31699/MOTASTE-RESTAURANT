<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
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

    $logs = DB::table('order_activity_logs')
        ->orderByDesc('created_at')
        ->limit(200)
        ->get()
        ->map(function ($row) {
            return [
                'id' => (int)($row->id ?? 0),
                'order_id' => $row->order_id !== null ? (int)$row->order_id : null,
                'order_number' => $row->order_number,
                'action' => (string)($row->action ?? ''),
                'actor_role' => $row->actor_role,
                'actor_email' => $row->actor_email,
                'summary' => $row->summary,
                'details' => $row->details ? json_decode((string)$row->details, true) : null,
                'created_at' => $row->created_at,
            ];
        })
        ->values()
        ->all();

    echo json_encode(['success' => true, 'logs' => $logs]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load order logs', 'details' => $error->getMessage()]);
}