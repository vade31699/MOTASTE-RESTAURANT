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

if ($id > 0) {
    $stmt = $mysqli->prepare('UPDATE staff SET full_name = ?, role = ?, email = ?, password_hash = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssssi', $name, $role, $email, $hash, $id);
} else {
    $lookupEmail = $currentEmail ?: $email;
    $stmt = $mysqli->prepare('UPDATE staff SET full_name = ?, role = ?, email = ?, password_hash = ?, updated_at = NOW() WHERE email = ?');
    $stmt->bind_param('sssss', $name, $role, $email, $hash, $lookupEmail);
}

$ok = $stmt->execute();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed', 'msg' => $stmt->error]);
    $stmt->close();
    $mysqli->close();
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();
$mysqli->close();

echo json_encode(['success' => true, 'updated' => $affected]);
