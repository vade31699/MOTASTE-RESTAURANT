<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireAdminAuth()) {
    abortStaffAuthRequired();
}

// The session was only needed to authenticate. Release its lock now
// (SESSION_DRIVER=file holds an exclusive flock for the whole request) so this
// read does not queue the browser's other staff requests behind it.
session_write_close();

require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;

try {
    ensureStaffLoginHistoryTable();

    // Optional date filter (?date=YYYY-MM-DD) limits the history to one day's
    // logins, keeping the credentials section clean and performant.
    $dateFilter = trim((string)($_GET['date'] ?? ''));
    if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid date. Use YYYY-MM-DD.']);
        exit;
    }

    $query = DB::table('staff_login_history');
    if ($dateFilter !== '') {
        // Half-open range instead of whereDate(): DATE(logged_in_at) = ? cannot
        // use the logged_in_at index, so the filtered view fell back to a full
        // table scan plus filesort on a table that grows with every login.
        $dayStart = $dateFilter . ' 00:00:00';
        $dayEnd = (new DateTimeImmutable($dateFilter))->modify('+1 day')->format('Y-m-d 00:00:00');
        $query->where('logged_in_at', '>=', $dayStart)->where('logged_in_at', '<', $dayEnd);
    }
    $rows = $query->orderByDesc('logged_in_at')->limit(100)->get();

    $history = $rows->map(static function ($row) {
        return [
            'id' => (int)($row->id ?? 0),
            'email' => maskEmailAddressForDisplay((string)($row->email ?? '')),
            'role' => (string)($row->role ?? ''),
            'full_name' => (string)($row->full_name ?? ''),
            'device_label' => (string)($row->device_label ?? ''),
            // DPA masking: keep enough prefix to correlate abuse, hide the rest.
            'ip_address' => maskIpAddressForDisplay((string)($row->ip_address ?? '')),
            'logged_in_at' => (string)($row->logged_in_at ?? ''),
        ];
    })->values()->all();

    echo json_encode([
        'success' => true,
        'history' => $history,
        'online' => getOnlineStaffAccounts(),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load login history']);
}
