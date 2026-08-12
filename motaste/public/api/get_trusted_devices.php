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

require_once __DIR__ . '/_device_auth_helpers.php';

try {
    // Read endpoint: the client sends email/deviceToken as query parameters.
    // Fall back to a JSON body for compatibility with other callers.
    $input = json_decode(file_get_contents('php://input'), true);
    $body = is_array($input) ? $input : [];
    $email = strtolower(trim((string)($_GET['email'] ?? ($body['email'] ?? ''))));
    $deviceToken = trim((string)($_GET['deviceToken'] ?? ($body['deviceToken'] ?? '')));
    $includeAll = !empty($_GET['includeAll']) || !empty($body['includeAll']);

    if ($email === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email is required.']);
        exit;
    }

    $currentFingerprint = $deviceToken !== '' ? computeDeviceFingerprint($email, $deviceToken) : '';

    // Credentials (admin-only view) lists trusted devices for every staff role
    // so Cashier and Inventory Manager devices can be labelled separately.
    $devices = DB::table('trusted_devices as td')
        ->leftJoin('staff as s', DB::raw('LOWER(s.email)'), '=', DB::raw('LOWER(td.email)'))
        ->select('td.*', 's.role as staff_role', 's.full_name as staff_name')
        ->when(!$includeAll, function ($query) use ($email) {
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
            'email' => (string)($row->email ?? ''),
            'role' => (string)($row->staff_role ?? ''),
            'device_label' => $label !== '' ? $label : 'Unknown device',
            'fingerprint' => (string)($row->fingerprint ?? ''),
            'ip_address' => (string)($row->ip_address ?? ''),
            'first_seen_at' => (string)($row->first_seen_at ?? ''),
            'last_seen_at' => (string)($row->last_seen_at ?? ''),
            'is_current' => $currentFingerprint !== '' && hash_equals($currentFingerprint, (string)($row->fingerprint ?? '')),
        ];
    })->values()->all();

    echo json_encode(['success' => true, 'devices' => $list]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load trusted devices', 'details' => $error->getMessage()]);
}
