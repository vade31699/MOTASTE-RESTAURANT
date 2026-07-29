<?php
header('Content-Type: text/plain');

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();

    $email = 'vadevidad31699@gmail.com';
    $password = 'March1699';
    $name = 'Administrator';

    $existingUser = DB::table('users')
        ->whereRaw('LOWER(email) = ?', [strtolower($email)])
        ->first();

    if ($existingUser) {
        $userId = $existingUser->id;
        DB::table('users')->where('id', $userId)->update([
            'name' => $name,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => now(),
        ]);
    } else {
        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'email_verified_at' => now(),
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $existingStaff = DB::table('staff')->where('user_id', $userId)->first();

    if ($existingStaff) {
        DB::table('staff')->where('id', $existingStaff->id)->update([
            'full_name' => $name,
            'role' => 'Admin',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => now(),
        ]);
    } else {
        DB::table('staff')->insert([
            'user_id' => $userId,
            'position' => null,
            'full_name' => $name,
            'role' => 'Admin',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::commit();
    echo 'Admin user created/updated successfully.';
} catch (Throwable $e) {
    DB::rollBack();
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}