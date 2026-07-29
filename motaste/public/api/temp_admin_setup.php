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
    $email = isset($_REQUEST['email']) ? trim((string)$_REQUEST['email']) : '';
    $password = isset($_REQUEST['password']) ? trim((string)$_REQUEST['password']) : '';
    $name = isset($_REQUEST['name']) ? trim((string)$_REQUEST['name']) : 'Administrator';

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email and password are required']);
        exit;
    }

    $email = strtolower($email);

    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS full_name VARCHAR(191)");
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS role VARCHAR(100)");
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(191)");
    DB::statement("ALTER TABLE staff ADD COLUMN IF NOT EXISTS password_hash VARCHAR(191)");

    $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($user) {
        DB::table('users')->where('id', $user->id)->update([
            'name' => $name,
            'password' => $passwordHash,
            'email_verified_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = $user->id;
    } else {
        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
            'email_verified_at' => now(),
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $staff = DB::table('staff')->where('user_id', $userId)->first();
    if ($staff) {
        DB::table('staff')->where('id', $staff->id)->update([
            'full_name' => $name,
            'role' => 'Admin',
            'email' => $email,
            'password_hash' => $passwordHash,
            'updated_at' => now(),
        ]);
    } else {
        $existingStaff = DB::table('staff')->whereRaw('LOWER(email) = ? OR LOWER(role) = ?', [$email, 'admin'])->first();
        if ($existingStaff) {
            DB::table('staff')->where('id', $existingStaff->id)->update([
                'user_id' => $userId,
                'full_name' => $name,
                'role' => 'Admin',
                'email' => $email,
                'password_hash' => $passwordHash,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('staff')->insert([
                'user_id' => $userId,
                'position' => null,
                'full_name' => $name,
                'role' => 'Admin',
                'email' => $email,
                'password_hash' => $passwordHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'email' => $email,
        'role' => 'Admin',
        'message' => 'Temporary admin credentials created/updated. Remove this file after use.'
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}
