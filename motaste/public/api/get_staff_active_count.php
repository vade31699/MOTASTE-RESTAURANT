<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}


use Illuminate\Support\Facades\DB;

try {
    // Heuristic: count sessions that appear to contain staff data in the payload.
    // The sessions payload format varies by session driver; use a case-insensitive search for the
    // substring "staff" which is used by some server-side session payloads.
    $count = DB::table('sessions')
        ->whereRaw('LOWER(payload) LIKE ?', ['%staff%'])
        ->count();

    echo json_encode(['success' => true, 'count' => (int)$count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to determine staff active count', 'details' => $e->getMessage()]);
}
