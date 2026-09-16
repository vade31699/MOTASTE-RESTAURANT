<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireInventoryAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();



use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_helpers.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Stored-XSS guard: reject HTML/script content anywhere in the menu snapshot
// (dish names, descriptions, etc.).
rejectUnsafeInputOrExit($input);

try {
    // Bound the snapshot size so one request cannot bloat the storage row or
    // force a huge JSON round-trip on every subsequent menu load.
    $snapshotJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($snapshotJson === false || strlen($snapshotJson) > 200000) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Menu snapshot is too large']);
        exit;
    }

    $now = now();
    DB::table('custom_menu_snapshots')->updateOrInsert(
        ['snapshot_key' => 'motaste-menu'],
        [
            'snapshot_payload' => $snapshotJson,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save custom menu snapshot']);
}