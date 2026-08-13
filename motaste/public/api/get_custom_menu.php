<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

// Provides IP-based rate limiting (recordOrderApiRequest / isOrderApiRateLimited).
require_once __DIR__ . '/_staff_auth_helpers.php';

use Illuminate\Support\Facades\DB;

// Per-IP abuse protection: the menu is fetched on page load, so a generous
// budget still stops scripted scraping.
recordOrderApiRequest('get_custom_menu');
if (isOrderApiRateLimited('get_custom_menu', 120, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again shortly.']);
    exit;
}

try {
    $snapshot = DB::table('custom_menu_snapshots')->where('snapshot_key', 'motaste-menu')->first();

    echo json_encode([
        'success' => true,
        'snapshot' => $snapshot ? json_decode($snapshot->snapshot_payload, true) : null,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load custom menu snapshot']);
}