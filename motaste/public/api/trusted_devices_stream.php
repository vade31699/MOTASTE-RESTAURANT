<?php

/**
 * Server-Sent Events stream of the trusted-devices list.
 *
 * Pushes the device list (same JSON shape as get_trusted_devices.php) whenever
 * it changes, plus a heartbeat comment every ~15s to keep intermediaries from
 * closing the connection. If a DB error occurs the loop exits with a retry
 * hint and the browser's EventSource reconnects automatically.
 *
 * Note: this holds a PHP-FPM worker for the lifetime of the connection. If the
 * hosting platform kills long-running requests, the client falls back to
 * interval polling (see script.js).
 */

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no'); // nginx: disable response buffering

// Keep the stream alive even if the client is slow; abort quietly on drop.
ignore_user_abort(false);
set_time_limit(0);

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
$actor = requireStaffAuth();
if (!$actor) {
    abortStaffAuthRequired();
}

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_device_auth_helpers.php';

// Free the PHP session lock immediately so the stream does not block other
// authenticated requests (revoke, CSRF refresh, etc.) in the same browser.
session_write_close();

$isAdmin = strtolower(trim((string)($actor['role'] ?? ''))) === 'admin';
$actorEmail = strtolower(trim((string)($actor['email'] ?? '')));
$deviceToken = trim((string)($_GET['deviceToken'] ?? ''));
$lastEventId = trim((string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['lastEventId'] ?? '')));

const STREAM_HEARTBEAT_SECONDS = 15;
const STREAM_MAX_LIFETIME_SECONDS = 300; // Recycle periodically; client reconnects.

/**
 * Build the masked device list exactly like get_trusted_devices.php so both
 * endpoints render identically.
 */
function buildTrustedDevicesPayload(string $actorEmail, bool $isAdmin, string $deviceToken): array
{
    $email = $isAdmin ? '' : $actorEmail;
    $currentFingerprint = $deviceToken !== '' ? computeDeviceFingerprint($actorEmail, $deviceToken) : '';

    $devices = DB::table('trusted_devices as td')
        ->leftJoin('staff as s', DB::raw('LOWER(s.email)'), '=', DB::raw('LOWER(td.email)'))
        ->select('td.*', 's.role as staff_role', 's.full_name as staff_name')
        ->when(!$isAdmin, function ($query) use ($email) {
            return $query->whereRaw('LOWER(td.email) = ?', [$email]);
        })
        ->orderByDesc('td.last_seen_at')
        ->limit(200)
        ->get();

    $list = $devices->map(function ($row) use ($currentFingerprint) {
        $label = (string)($row->device_label ?? '');
        if ($label === '' && $row->user_agent) {
            $label = resolveDeviceLabel((string)$row->user_agent);
        }
        return [
            'id' => (int)($row->id ?? 0),
            'email' => maskEmailAddressForDisplay((string)($row->email ?? '')),
            'role' => (string)($row->staff_role ?? ''),
            'device_label' => $label !== '' ? $label : 'Unknown device',
            'fingerprint' => (string)($row->fingerprint ?? ''),
            'ip_address' => maskIpAddressForDisplay((string)($row->ip_address ?? '')),
            'first_seen_at' => (string)($row->first_seen_at ?? ''),
            'last_seen_at' => (string)($row->last_seen_at ?? ''),
            'is_current' => $currentFingerprint !== '' && hash_equals($currentFingerprint, (string)($row->fingerprint ?? '')),
        ];
    })->values()->all();

    return ['success' => true, 'devices' => $list];
}

function sendSseEvent(string $eventName, array $payload, string &$lastEventId): void
{
    $lastEventId = (string)time();
    echo 'id: ' . $lastEventId . "\n";
    echo 'event: ' . $eventName . "\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    // Flush past PHP, FPM, and any zlib output buffering.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

function sendSseComment(string $comment): void
{
    echo ': ' . $comment . "\n\n";
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

// Tell the browser to retry after 5s if the connection drops.
echo "retry: 5000\n\n";
flush();

// On reconnect, replay the current state first so the client is immediately
// consistent even if it reconnected after the server recycled the stream.
$lastSent = null;
$sentAt = time();

try {
    while (true) {
        if (connection_aborted()) {
            break;
        }

        if ((time() - $sentAt) >= STREAM_MAX_LIFETIME_SECONDS) {
            break; // Recycle the stream; EventSource reconnects automatically.
        }

        try {
            $payload = buildTrustedDevicesPayload($actorEmail, $isAdmin, $deviceToken);
            $snapshot = md5(json_encode($payload));
            if ($snapshot !== $lastSent) {
                $lastSent = $snapshot;
                sendSseEvent('devices', $payload, $lastEventId);
            } else {
                sendSseComment('keep-alive');
            }
        } catch (Throwable $dbError) {
            // Transient DB issues: log, tell the client to back off, and end.
            error_log('trusted_devices_stream DB error: ' . $dbError->getMessage());
            sendSseEvent('stream-error', ['success' => false, 'error' => 'Stream temporarily unavailable.'], $lastEventId);
            break;
        }

        sleep(STREAM_HEARTBEAT_SECONDS);
    }
} catch (Throwable $error) {
    // Client disconnected or fatal error — nothing to do; the browser retries.
}

exit;
