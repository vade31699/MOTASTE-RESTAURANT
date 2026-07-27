<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/csrf_guard.php';

$csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($csrfToken === '' || !hash_equals(getOrCreateCsrfToken(), $csrfToken)) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid CSRF token. Please refresh and try again.',
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['slides']) || !is_array($input['slides'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$slides = array_values(array_filter($input['slides'], static function ($item) {
    return is_string($item) && trim($item) !== '';
}));

if (count($slides) > 15) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Maximum of 15 highlight images is allowed.',
    ]);
    exit;
}

try {
    DB::statement("CREATE TABLE IF NOT EXISTS highlights_snapshots (
        id BIGSERIAL PRIMARY KEY,
        snapshot_key VARCHAR(191) NOT NULL UNIQUE,
        snapshot_payload TEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");

    $now = now();
    DB::table('highlights_snapshots')->updateOrInsert(
        ['snapshot_key' => 'motaste-highlights'],
        [
            'snapshot_payload' => json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    echo json_encode([
        'success' => true,
        'slides' => $slides,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to save highlights snapshot',
        'details' => $error->getMessage(),
    ]);
}
