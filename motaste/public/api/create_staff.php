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

$full = isset($input['name']) ? trim($input['name']) : '';
$role = isset($input['role']) ? trim($input['role']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? $input['password'] : '';
if (!$full || !$role || !$email || !$password) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare('INSERT INTO staff (full_name, role, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$full, $role, $email, $hash]);
    
    // Get inserted ID
    $insertId = $pdo->lastInsertId('staff_id_seq');
    echo json_encode(['success'=>true,'id'=>$insertId]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Insert failed','msg'=>$e->getMessage()]);
    exit;
}
