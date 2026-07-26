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
    DB::statement("CREATE TABLE IF NOT EXISTS custom_menu_snapshots (
        id BIGSERIAL PRIMARY KEY,
        snapshot_key VARCHAR(191) NOT NULL UNIQUE,
        snapshot_payload TEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    $snapshot = DB::table('custom_menu_snapshots')->where('snapshot_key', 'motaste-menu')->first();

    echo json_encode([
        'success' => true,
        'snapshot' => $snapshot ? json_decode($snapshot->snapshot_payload, true) : null,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load custom menu snapshot', 'details' => $error->getMessage()]);
}