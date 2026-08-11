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
require_once __DIR__ . '/csrf_guard.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

validateCsrfOrExit();

$input = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['orderId']) ? (int)$input['orderId'] : 0;
$minutes = isset($input['minutes']) ? (int)$input['minutes'] : 0;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'orderId is required']);
    exit;
}

if ($minutes < 1 || $minutes > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Estimated preparation minutes must be between 1 and 180.']);
    exit;
}

try {
    ensureOrderPrepTimerColumns();

    $result = DB::transaction(function () use ($orderId, $minutes) {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();

        if (!$order) {
            return ['success' => false, 'status' => 404, 'error' => 'Order not found'];
        }

        $status = strtolower((string)($order->status ?? ''));
        if ($status === 'completed' || $status === 'expired') {
            return [
                'success' => false,
                'status' => 409,
                'error' => 'This order can no longer be prepared because it is already ' . $status . '.',
            ];
        }

        $now = now()->toDateTimeString();

        // Starting preparation (or updating the estimate for an accepted order).
        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'prep_minutes' => $minutes,
                'prep_started_at' => $order->prep_started_at ?? $now,
                'updated_at' => $now,
            ]);

        return [
            'success' => true,
            'orderNumber' => $order->order_number,
            'prepMinutes' => $minutes,
            'prepStartedAt' => $order->prep_started_at ?? $now,
        ];
    });

    if (!$result['success']) {
        http_response_code($result['status'] ?? 500);
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Unable to start order preparation']);
        exit;
    }

    try {
        DB::table('order_activity_logs')->insert([
            'order_id' => $orderId,
            'order_number' => $result['orderNumber'] ?? null,
            'action' => 'order_preparing',
            'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
            'actor_email' => $actorEmail !== '' ? $actorEmail : null,
            'summary' => 'Order accepted and preparation started',
            'details' => json_encode([
                'event' => 'Order accepted for preparation',
                'prep_minutes' => $result['prepMinutes'],
                'prep_started_at' => $result['prepStartedAt'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $logError) {
        error_log('order_preparing activity log insert failed: ' . $logError->getMessage());
    }

    // Insert order event so other staff dashboards refresh in real time.
    try {
        if (!Schema::hasTable('order_events')) {
            Schema::create('order_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('order_number')->nullable()->index();
                $table->string('event_type', 64)->index();
                $table->string('order_type', 64)->nullable()->index();
                $table->text('payload')->nullable();
                $table->timestamps();
            });
        }

        $orderRow = DB::table('orders')->where('id', $orderId)->first();
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'order_number' => $result['orderNumber'] ?? null,
            'event_type' => 'order_preparing',
            'order_type' => $orderRow ? ($orderRow->order_type ?? '') : '',
            'payload' => json_encode(['prep_minutes' => $result['prepMinutes']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $eventError) {
        error_log('order_events insert failed (preparing): ' . $eventError->getMessage());
    }

    // Notify the customer that preparation has started (best-effort).
    try {
        require_once __DIR__ . '/_email_auth_helpers.php';

        $customerRow = DB::table('orders')
            ->where('id', $orderId)
            ->first(['customer_email', 'customer_name', 'order_number', 'total_amount']);
        if ($customerRow) {
            $customerEmail = trim((string)($customerRow->customer_email ?? ''));
            if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $displayName = trim((string)($customerRow->customer_name ?? ''));
                sendSystemEmail(
                    $customerEmail,
                    'Your MOTASTE order is being prepared',
                    'Hi ' . ($displayName !== '' ? $displayName : 'there')
                        . ",\n\nGood news — your order #" . (string)($customerRow->order_number ?? $orderId)
                        . " is now being prepared. Estimated time: " . (int)$result['prepMinutes'] . " minutes.\n\n"
                        . 'Total: ₱' . number_format((float)($customerRow->total_amount ?? 0), 2)
                        . "\n\nThank you for choosing MOTASTE!"
                );
            }
        }
    } catch (Throwable $notifyError) {
        error_log('order preparing email failed: ' . $notifyError->getMessage());
    }

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'orderNumber' => $result['orderNumber'] ?? null,
        'prepMinutes' => $result['prepMinutes'],
        'prepStartedAt' => $result['prepStartedAt'],
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to start order preparation', 'details' => $error->getMessage()]);
}
