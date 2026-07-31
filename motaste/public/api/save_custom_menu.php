<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    DB::statement("CREATE TABLE IF NOT EXISTS custom_menu_snapshots (
        id BIGSERIAL PRIMARY KEY,
        snapshot_key VARCHAR(191) NOT NULL UNIQUE,
        snapshot_payload LONGTEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    $now = now();
    DB::table('custom_menu_snapshots')->updateOrInsert(
        ['snapshot_key' => 'motaste-menu'],
        [
            'snapshot_payload' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save custom menu snapshot', 'details' => $error->getMessage()]);
}