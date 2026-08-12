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

// Per-IP abuse protection for the public slideshow data.
recordOrderApiRequest('get_highlights');
if (isOrderApiRateLimited('get_highlights', 120, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again shortly.']);
    exit;
}

try {
    
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
