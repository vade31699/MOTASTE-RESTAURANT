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


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_email_auth_helpers.php';
require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_password_policy.php';

validateCsrfOrExit();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    // Stored-XSS guard: names/emails must be plain text (passwords are hashed
    // and never rendered, so they are intentionally not checked here).
    foreach ($input as $account) {
        if (is_array($account)) {
            rejectUnsafeInputOrExit($account['name'] ?? '', $account['email'] ?? '');
        }
    }

    // Strong password policy: reject weak plaintext passwords before they are
    // hashed and persisted. Admin accounts get the elevated 12-character
    // minimum; Cashier / Inventory Manager accounts use the standard 8.
    foreach ($input as $account) {
        if (!is_array($account)) {
            continue;
        }
        $candidate = (string)($account['password'] ?? '');
        if ($candidate === '') {
            continue;
        }
        $confirmation = (string)($account['password_confirmation'] ?? '');
        if ($candidate !== $confirmation) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Password confirmation does not match for ' . ($account['email'] ?? 'unknown account')]);
            exit;
        }
        $accountRole = strtolower(trim((string)($account['role'] ?? '')));
        enforce_password_policy($candidate, forAdmin: $accountRole === 'admin');

        // Reject reusing the account's current password: the staff portal reads
        // staff.password_hash, so a "change" to the same value would leave the
        // existing credential valid. Skipped for brand-new accounts (no row).
        $accountEmail = strtolower(trim((string)($account['email'] ?? '')));
        if ($accountEmail !== '') {
            $existingHash = DB::table('staff')
                ->whereRaw('LOWER(email) = ?', [$accountEmail])
                ->value('password_hash');
            // Admin hashes live in the dedicated `admins` table.
            if ($existingHash === null && Schema::hasTable('admins')) {
                $existingHash = DB::table('admins')
                    ->whereRaw('LOWER(email) = ?', [$accountEmail])
                    ->value('password_hash');
            }
            if (is_password_reused($candidate, [$existingHash])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'New password must be different from the current password for ' . $accountEmail]);
                exit;
            }
        }
    }

    saveStaffAccountsSnapshot($input);

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save staff accounts']);
}
