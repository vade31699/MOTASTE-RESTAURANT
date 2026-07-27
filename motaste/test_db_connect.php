<?php
// Load Laravel environment variables
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "Error: .env file not found at {$envFile}\n";
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

echo "Testing PostgreSQL connection from .env:\n";
echo "Host: {$host}\n";
echo "Port: {$port}\n";
echo "Database: {$db}\n";
echo "User: {$user}\n\n";

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ PostgreSQL connection: OK\n";
} catch (PDOException $e) {
    echo "✗ PostgreSQL connection failed: " . $e->getMessage() . "\n";
}
