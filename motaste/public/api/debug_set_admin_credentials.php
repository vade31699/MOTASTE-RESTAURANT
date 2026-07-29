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
    // Accept POST first, fallback to GET for quick testing
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : (isset($_GET['email']) ? trim((string)$_GET['email']) : '');
    $password = isset($_POST['password']) ? trim((string)$_POST['password']) : (isset($_GET['password']) ? trim((string)$_GET['password']) : '');

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing email or password parameter.']);
        exit;
    }

    $email = strtolower($email);

    // Find an existing admin row (role contains 'admin') or fallback to any row with admin-like email
    $adminRow = DB::table('staff')
        ->whereRaw('LOWER(role) LIKE ?', ['%admin%'])
        ->orWhereRaw('LOWER(email) LIKE ?', ['%admin%'])
        ->first();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($adminRow) {
        DB::table('staff')->where('id', $adminRow->id)->update([
            'email' => $email,
            'password_hash' => $passwordHash,
            'updated_at' => now(),
        ]);
    } else {
        DB::table('staff')->insert([
            'full_name' => 'Administrator',
            'role' => 'Admin',
            'email' => $email,
            'password_hash' => $passwordHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    echo json_encode(['success' => true, 'email' => $email]);
} catch (Throwable $err) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err->getMessage()]);
}
