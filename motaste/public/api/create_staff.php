<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }
$host='127.0.0.1'; $db='motaste_db'; $user='root'; $pass='';
$mysqli = new mysqli($host,$user,$pass,$db);
if ($mysqli->connect_error) { http_response_code(500); echo json_encode(['error'=>'DB connect failed']); exit; }
$full = isset($input['name']) ? trim($input['name']) : '';
$role = isset($input['role']) ? trim($input['role']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? $input['password'] : '';
if (!$full || !$role || !$email || !$password) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('INSERT INTO staff (full_name, role, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
$stmt->bind_param('ssss', $full, $role, $email, $hash);
$ok = $stmt->execute();
if (!$ok) { http_response_code(500); echo json_encode(['error'=>'Insert failed','msg'=>$stmt->error]); exit; }
$insertId = $mysqli->insert_id;
$stmt->close();
$mysqli->close();
echo json_encode(['success'=>true,'id'=>$insertId]);
