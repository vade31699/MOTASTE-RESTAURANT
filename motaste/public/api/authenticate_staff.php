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
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $selectedRole = trim((string)($input['role'] ?? ''));

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    $staffRow = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (!$staffRow || !isset($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    $role = trim((string)($staffRow->role ?? ''));
    if ($selectedRole !== '' && $selectedRole !== $role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid role for this account']);
        exit;
    }

    $inviteConfirmed = true;
    if (in_array($role, ['Cashier', 'Inventory Manager'], true)) {
        $token = DB::table('staff_invite_tokens')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereRaw('LOWER(role) = ?', [strtolower($role)])
            ->first();

        if ($token) {
            $inviteConfirmed = false;
        }
    }

    echo json_encode([
        'success' => true,
        'role' => $role,
        'email' => strtolower(trim((string)($staffRow->email ?? ''))),
        'name' => trim((string)($staffRow->full_name ?? '')),
        'inviteConfirmed' => $inviteConfirmed
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to authenticate staff account', 'details' => $error->getMessage()]);
}
