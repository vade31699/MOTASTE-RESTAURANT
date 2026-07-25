<?php
header('Content-Type: application/json');

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$db = 'motaste_db';

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$mysqli->query("CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL UNIQUE,
    price DECIMAL(10,2) DEFAULT 0,
    stock INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'Out of stock',
    category VARCHAR(100) DEFAULT 'specials',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$result = $mysqli->query("SELECT name, price, stock, status, category FROM inventory_items ORDER BY id ASC");
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'name' => $row['name'],
        'price' => (float)($row['price'] ?? 0),
        'stock' => (int)($row['stock'] ?? 0),
        'status' => $row['status'] ?? ((int)($row['stock'] ?? 0) > 0 ? 'In stock' : 'Out of stock'),
        'category' => $row['category'] ?? 'specials'
    ];
}

$mysqli->close();
echo json_encode(['success' => true, 'items' => $items]);
