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
    DB::statement("CREATE TABLE IF NOT EXISTS highlights_snapshots (
        id BIGSERIAL PRIMARY KEY,
        snapshot_key VARCHAR(191) NOT NULL UNIQUE,
        snapshot_payload TEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    $snapshot = DB::table('highlights_snapshots')
        ->where('snapshot_key', 'motaste-highlights')
        ->first();

    $slides = [];
    if ($snapshot && isset($snapshot->snapshot_payload)) {
        $decoded = json_decode((string)$snapshot->snapshot_payload, true);
        if (is_array($decoded)) {
            $slides = array_values(array_filter($decoded, static function ($item) {
                return is_string($item) && trim($item) !== '';
            }));
        }
    }

    echo json_encode([
        'success' => true,
        'slides' => array_slice($slides, 0, 15),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load highlights snapshot',
        'details' => $error->getMessage(),
    ]);
}
