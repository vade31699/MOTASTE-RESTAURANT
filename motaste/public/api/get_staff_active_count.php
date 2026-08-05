<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    // Prefer explicit tracking via `staff_sessions` table (created by notify_staff_session).
    if (Schema::hasTable('staff_sessions')) {
        // Clean up stale sessions older than the configured timeout (minutes)
        $timeoutMinutes = 15; // configurable timeout for inactivity
        try {
            DB::table('staff_sessions')
                ->where('is_active', true)
                ->where('last_seen', '<', DB::raw("(NOW() - INTERVAL '{$timeoutMinutes} minutes')"))
                ->update(['is_active' => false, 'last_action' => 'expired', 'updated_at' => now()]);
        } catch (Throwable $__cleanupError) {
            // ignore cleanup errors
        }

        $count = DB::table('staff_sessions')->where('is_active', true)->count();
        echo json_encode(['success' => true, 'count' => (int)$count]);
        exit;
    }

    // Fallback heuristic: search sessions payload for the "staff" substring.
    $count = DB::table('sessions')
        ->whereRaw('LOWER(payload) LIKE ?', ['%staff%'])
        ->count();

    echo json_encode(['success' => true, 'count' => (int)$count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to determine staff active count', 'details' => $e->getMessage()]);
}
