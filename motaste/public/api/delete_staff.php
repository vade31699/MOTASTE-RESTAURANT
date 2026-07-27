<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }
$host='127.0.0.1'; $db='motaste_db'; $user='root'; $pass='';
$mysqli = new mysqli($host,$user,$pass,$db);
if ($mysqli->connect_error) { http_response_code(500); echo json_encode(['error'=>'DB connect failed']); exit; }
$email = isset($input['email']) ? trim($input['email']) : '';
if (!$email) { http_response_code(400); echo json_encode(['error'=>'Missing email']); exit; }
$stmt = $mysqli->prepare('DELETE FROM staff WHERE email = ?');
$stmt->bind_param('s', $email);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$mysqli->close();
if ($ok) echo json_encode(['success'=>true,'deleted'=>$affected]); else { http_response_code(500); echo json_encode(['error'=>'Delete failed']); }
