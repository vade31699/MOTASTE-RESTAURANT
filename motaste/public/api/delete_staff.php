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

$email = isset($input['email']) ? trim($input['email']) : '';
if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing email']);
    exit;
}

try {
    $deleted = DB::table('staff')
        ->where('email', $email)
        ->delete();

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed', 'details' => $error->getMessage()]);
}
