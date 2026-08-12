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
use Carbon\Carbon;

try {
    
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
                'created_at_iso' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ];
        })
        ->values()
        ->all();

    echo json_encode(['success' => true, 'logs' => $logs]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load order logs', 'details' => apiErrorDetail($error)]);
}