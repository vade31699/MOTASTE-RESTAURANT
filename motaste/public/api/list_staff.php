<?php
header('Content-Type: application/json');
$host = '127.0.0.1';
$db = 'motaste_db';
$user = 'root';
$pass = '';
$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) { http_response_code(500); echo json_encode(['error'=>'DB connect failed']); exit; }
$res = $mysqli->query('SELECT id, full_name, role, email FROM staff ORDER BY id ASC');
$out = [];
if ($res) { while ($row = $res->fetch_assoc()) $out[] = $row; $res->free(); }
$mysqli->close();
echo json_encode(['staff'=>$out]);
