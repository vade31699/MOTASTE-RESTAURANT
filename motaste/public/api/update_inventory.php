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

$input = json_decode(file_get_contents('php://input'), true);
$name = isset($input['name']) ? trim($input['name']) : '';
$price = isset($input['price']) ? (float)$input['price'] : 0;
$stock = isset($input['stock']) ? (int)$input['stock'] : 0;
$category = isset($input['category']) ? trim($input['category']) : 'specials';
$status = isset($input['status']) ? trim($input['status']) : ($stock > 0 ? 'In stock' : 'Out of stock');

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Item name is required']);
    $mysqli->close();
    exit;
}

$normalizedStatus = $stock > 0 ? ($status === 'Out of stock' ? 'In stock' : $status) : 'Out of stock';

$stmt = $mysqli->prepare('SELECT id FROM inventory_items WHERE name = ? LIMIT 1');
$stmt->bind_param('s', $name);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $updateStmt = $mysqli->prepare('UPDATE inventory_items SET price = ?, stock = ?, status = ?, category = ? WHERE name = ?');
    $updateStmt->bind_param('disss', $price, $stock, $normalizedStatus, $category, $name);
    $updateStmt->execute();
    $itemId = $row['id'];
    $updateStmt->close();
} else {
    $insertStmt = $mysqli->prepare('INSERT INTO inventory_items (name, price, stock, status, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $insertStmt->bind_param('sdiss', $name, $price, $stock, $normalizedStatus, $category);
    $insertStmt->execute();
    $itemId = $mysqli->insert_id;
    $insertStmt->close();
}

$stmt->close();
$mysqli->close();

echo json_encode(['success' => true, 'itemId' => $itemId, 'stock' => $stock, 'status' => $normalizedStatus]);
