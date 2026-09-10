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


require_once __DIR__ . '/_device_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';

try {
    validateCsrfOrExit();

    $input = json_decode(file_get_contents('php://input'), true);
    $enabled = filter_var($input['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($enabled === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'The enabled flag is required.']);
        exit;
    }

    setTrustDeviceEnabled($enabled);

    // Audit the change so there is a trail of who toggled device trust.
    try {
        logApiEvent('trust_device_setting_changed', [
            'enabled' => $enabled,
            'actor_email' => strtolower(trim((string)($_SESSION['staff']['email'] ?? ''))),
            'changed_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $logError) {
        // Auditing must never block the response.
    }

    echo json_encode([
        'success' => true,
        'trustDeviceEnabled' => $enabled,
        'message' => $enabled
            ? 'Trusted devices are now enabled. Recognized devices can sign in without a verification code.'
            : 'Trusted devices are now disabled. Every staff login will require an emailed verification code.',
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update trust device setting']);
}