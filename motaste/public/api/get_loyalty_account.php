<?php

/**
 * GET /api/get_loyalty_account.php?phone=09XXXXXXXXX
 * Looks up a customer's loyalty account by phone number (staff only).
 */

use Illuminate\Support\Facades\DB;

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_staff_auth_helpers.php';

if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}

header('Content-Type: application/json');

$phone = trim((string)($_GET['phone'] ?? ''));
if ($phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing phone number.']);
    exit;
}

try {
    $account = getLoyaltyAccount($phone);
    if (!$account) {
        echo json_encode([
            'success' => true,
            'found' => false,
            'account' => null,
        ]);
        exit;
    }

    logApiEvent('loyalty_lookup', ['phone' => $phone]);

    echo json_encode([
        'success' => true,
        'found' => true,
        'account' => [
            'phone' => $account->phone,
            'name' => $account->name,
            'points' => (int)$account->points,
            'redemptionPoints' => LOYALTY_REDEMPTION_POINTS,
            'redemptionValue' => LOYALTY_REDEMPTION_VALUE,
        ],
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Loyalty lookup failed.']);
    error_log('get_loyalty_account.php error: ' . $error->getMessage());
}
