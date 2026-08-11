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


require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;

try {
    ensureStaffLoginHistoryTable();

    $rows = DB::table('staff_login_history')
        ->orderByDesc('logged_in_at')
        ->limit(100)
        ->get();

    $history = $rows->map(static function ($row) {
        return [
            'id' => (int)($row->id ?? 0),
            'email' => (string)($row->email ?? ''),
            'role' => (string)($row->role ?? ''),
            'full_name' => (string)($row->full_name ?? ''),
            'device_label' => (string)($row->device_label ?? ''),
            'ip_address' => (string)($row->ip_address ?? ''),
            'logged_in_at' => (string)($row->logged_in_at ?? ''),
        ];
    })->values()->all();

    echo json_encode(['success' => true, 'history' => $history]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load login history', 'details' => $error->getMessage()]);
}
