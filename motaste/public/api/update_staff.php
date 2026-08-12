<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$name = isset($input['name']) ? trim($input['name']) : '';
$role = isset($input['role']) ? trim($input['role']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? $input['password'] : '';
$currentEmail = isset($input['currentEmail']) ? trim($input['currentEmail']) : '';
$id = isset($input['id']) ? (int) $input['id'] : 0;

if (!$name || !$role || !$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

$lookupEmail = $currentEmail ?: $email;
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $query = DB::table('staff');

    if ($id > 0) {
        $query->where('id', $id);
    } else {
        $query->where('email', $lookupEmail);
    }

    $updated = $query->update([
        'full_name' => $name,
        'role' => $role,
        'email' => $email,
        'password_hash' => $hash,
        'updated_at' => now(),
    ]);

    echo json_encode(['success' => true, 'updated' => $updated]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed', 'details' => $error->getMessage()]);
}
