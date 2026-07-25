<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}
$host = '127.0.0.1';
$db = 'motaste_db';
$user = 'root';
$pass = '';
$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed']);
    exit;
}
// expected input: { orderNumber, items: [{name, price, quantity}], paymentMethod, orderType }
$orderNumber = $input['orderNumber'] ?? (string)time();
$items = $input['items'] ?? [];
$paymentMethod = $input['paymentMethod'] ?? 'Cash';
$orderType = $input['orderType'] ?? 'Dine In';
$subtotal = 0;
foreach ($items as $it) {
    $subtotal += (float)$it['price'] * (int)$it['quantity'];
}
$total = $subtotal; // no tax/discount for now
$now = date('Y-m-d H:i:s');
$stmt = $mysqli->prepare('INSERT INTO orders (order_number, customer_id, table_id, staff_id, order_date, status, payment_status, subtotal, tax_amount, discount_amount, total_amount, notes, created_at, updated_at) VALUES (?, NULL, NULL, NULL, ?, ?, ?, ?, 0, 0, ?, NULL, ?, ?)');
$status = 'pending';
$payment_status = 'unpaid';
$stmt->bind_param('ssssddss', $orderNumber, $now, $status, $payment_status, $subtotal, $total, $now, $now);
$ok = $stmt->execute();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Insert order failed', 'msg' => $stmt->error]);
    exit;
}
$orderId = $mysqli->insert_id;
$stmt->close();
$inserted = 0;
// temporarily disable FK checks for flexible test inserts
$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach ($items as $it) {
    $name = $it['name'];
    $price = (float)$it['price'];
    $qty = (int)$it['quantity'];
    $lineTotal = $price * $qty;
    $itemName = $it['name'] ?? 'Menu item';
    $stmt = $mysqli->prepare('INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, line_total, notes, created_at, updated_at) VALUES (?, 0, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iiddsss', $orderId, $qty, $price, $lineTotal, $itemName, $now, $now);
    if ($stmt->execute()) $inserted++;
    $stmt->close();
}
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');
$mysqli->close();
echo json_encode(['success' => true, 'orderId' => $orderId, 'insertedItems' => $inserted]);
