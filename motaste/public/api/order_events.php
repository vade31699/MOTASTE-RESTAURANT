<?php
// Server-Sent Events endpoint for order events (created/completed)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
set_time_limit(0);

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

// Provides IP-based rate limiting (recordOrderApiRequest / isOrderApiRateLimited)
// plus the staff session gate. The live order stream contains every order's
// items, so it is restricted to authenticated staff only.
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}

use Illuminate\Support\Facades\DB;

// Per-IP abuse protection: each connection is a long-lived SSE stream, so the
// budget limits how often a client can (re)open a connection, not its polling.
recordOrderApiRequest('order_events');
if (isOrderApiRateLimited('order_events', 60, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again shortly.']);
    exit;
}

$lastId = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastId = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
}
if (isset($_GET['lastId'])) {
    $lastId = max($lastId, (int) $_GET['lastId']);
}

echo "retry: 2000\n\n"; // ask client to retry after 2s on disconnect
ob_flush();
flush();

while (!connection_aborted()) {
    try {
        $events = DB::table('order_events')
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($events && count($events) > 0) {
            foreach ($events as $ev) {
                $lastId = (int) $ev->id;
                $data = [
                    'id' => $ev->id,
                    'order_id' => $ev->order_id,
                    'order_number' => $ev->order_number,
                    'event_type' => $ev->event_type,
                    'order_type' => $ev->order_type,
                    'payload' => $ev->payload ? json_decode($ev->payload, true) : null,
                    'created_at' => $ev->created_at,
                ];

                $eventName = $ev->event_type === 'order_completed' ? 'order_completed' : 'order_created';
                echo "id: {$ev->id}\n";
                echo "event: {$eventName}\n";
                echo 'data: ' . json_encode($data) . "\n\n";
                ob_flush();
                flush();
            }
        }
    } catch (Throwable $e) {
        // ignore transient DB errors
    }

    // sleep briefly before polling again
    usleep(500000); // 0.5s
}
