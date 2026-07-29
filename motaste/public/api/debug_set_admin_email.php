<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
    if ($email === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing email parameter.']);
        exit;
    }

    $email = strtolower($email);

    // Try to find an admin row by role (case-insensitive) or by existing admin-like emails
    $adminRow = DB::table('staff')
        ->whereRaw('LOWER(role) LIKE ?', ['%admin%'])
        ->orWhereRaw('LOWER(email) LIKE ?', ['%admin%'])
        ->first();

    if ($adminRow) {
        DB::table('staff')->where('id', $adminRow->id)->update([
            'email' => $email,
            'updated_at' => now(),
        ]);
    } else {
        // Insert a new admin row with no password (set later)
        DB::table('staff')->insert([
            'full_name' => 'Administrator',
            'role' => 'Admin',
            'email' => $email,
            'password_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    echo json_encode(['success' => true, 'email' => $email]);
} catch (Throwable $err) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err->getMessage()]);
}
