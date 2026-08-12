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

require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Best-effort: prep columns are guaranteed by the helper (or the migration).
ensureOrderPrepTimerColumns();

// Rate-limit status lookups per IP to stop mass probing of order numbers.
if (isOrderApiRateLimited('order_status', ORDER_STATUS_MAX_PER_WINDOW, ORDER_STATUS_WINDOW_SECONDS)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many status checks. Please try again shortly.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$orderNumbers = is_array($input['orderNumbers'] ?? null) ? $input['orderNumbers'] : [];
$customerPhone = trim((string)($input['customerPhone'] ?? ''));

if ($customerPhone === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'customerPhone is required to check order status']);
    exit;
}

if (!is_array($orderNumbers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderNumbers must be an array']);
    exit;
}

$normalizedOrderNumbers = array_values(array_filter(array_map(static function ($value) {
    return trim((string)$value);
}, $orderNumbers), static function ($value) {
    return $value !== '';
}));

if (empty($normalizedOrderNumbers)) {
    echo json_encode(['success' => true, 'orders' => []]);
    exit;
}

try {
    $orders = DB::table('orders')
        ->whereIn('order_number', $normalizedOrderNumbers)
        ->orderByDesc('updated_at')
        ->get(['id', 'order_number', 'status', 'order_type', 'customer_phone', 'prep_minutes', 'prep_started_at', 'updated_at']);

    // Ownership check: only return orders whose customer phone matches the
    // submitted number, so order numbers cannot be enumerated by strangers.
    $normalizePhone = static function (string $phone): string {
        return preg_replace('/\D+/', '', $phone);
    };
    $submittedPhone = $normalizePhone($customerPhone);

    $matched = $orders->filter(static function ($order) use ($normalizePhone, $submittedPhone) {
        $orderPhone = trim((string)($order->customer_phone ?? ''));
        if ($orderPhone === '') {
            return false;
        }
        return $normalizePhone($orderPhone) === $submittedPhone;
    });

    $result = $matched->map(static function ($order) {
        return [
            'id' => (int)$order->id,
            'order_number' => (string)$order->order_number,
            'status' => (string)$order->status,
            'order_type' => (string)($order->order_type ?? ''),
            'prep_minutes' => isset($order->prep_minutes) && $order->prep_minutes !== null ? (int)$order->prep_minutes : null,
            'prep_started_at' => $order->prep_started_at ?? null,
            // Timezone-aware ISO variant so the browser countdown computes
            // remaining time against the same UTC instant the server used.
            'prep_started_at_iso' => $order->prep_started_at ? Carbon::parse($order->prep_started_at)->toIso8601String() : null,
            'updated_at' => $order->updated_at,
        ];
    })->values()->all();

    recordOrderApiRequest('order_status');

    echo json_encode(['success' => true, 'orders' => $result]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to fetch order status', 'details' => apiErrorDetail($error)]);
}
