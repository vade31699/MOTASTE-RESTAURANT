<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

// Load Laravel environment variables
$envFile = __DIR__ . '/../../.env';
if (!file_exists($envFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration not found']);
    exit;
}

$env = [];
foreach (file($envFile) as $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, '#') === 0) continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($key)] = trim($value);
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? 5432;
$db = $env['DB_DATABASE'] ?? 'motaste_db';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

$email = isset($input['email']) ? trim($input['email']) : '';
if (!$email) { http_response_code(400); echo json_encode(['error'=>'Missing email']); exit; }

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare('DELETE FROM staff WHERE email = ?');
    $stmt->execute([$email]);
    $affected = $stmt->rowCount();
    
    echo json_encode(['success'=>true,'deleted'=>$affected]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Delete failed', 'msg'=>$e->getMessage()]);
    exit;
}
