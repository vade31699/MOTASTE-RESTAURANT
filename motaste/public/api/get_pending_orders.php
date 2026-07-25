<?php
header('Content-Type: application/json');
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
$orders = [];
$res = $mysqli->query("SELECT * FROM orders WHERE status = 'pending' ORDER BY order_date DESC LIMIT 100");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $orderId = $row['id'];
        $itemsRes = $mysqli->query("SELECT * FROM order_items WHERE order_id = " . (int)$orderId);
        $items = [];
        if ($itemsRes) {
            while ($it = $itemsRes->fetch_assoc()) {
                $it['name'] = $it['notes'] ?: 'Menu item';
                $it['price'] = (float)($it['unit_price'] ?? 0);
                $it['quantity'] = (int)($it['quantity'] ?? 0);
                $items[] = $it;
            }
            $itemsRes->free();
        }
        $row['items'] = $items;
        $orders[] = $row;
    }
    $res->free();
}
$mysqli->close();
echo json_encode(['orders' => $orders]);
