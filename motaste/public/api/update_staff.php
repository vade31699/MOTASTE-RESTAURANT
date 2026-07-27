<?php
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

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

// Map Laravel PostgreSQL config to connection
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? 5432;
$db = $env['DB_DATABASE'] ?? 'motaste_db';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed']);
    exit;
}

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

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE staff SET full_name = ?, role = ?, email = ?, password_hash = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$name, $role, $email, $hash, $id]);
    } else {
        $lookupEmail = $currentEmail ?: $email;
        $stmt = $pdo->prepare('UPDATE staff SET full_name = ?, role = ?, email = ?, password_hash = ?, updated_at = NOW() WHERE email = ?');
        $stmt->execute([$name, $role, $email, $hash, $lookupEmail]);
    }
    
    $affected = $stmt->rowCount();
    echo json_encode(['success' => true, 'updated' => $affected]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed', 'msg' => $e->getMessage()]);
    exit;
}
