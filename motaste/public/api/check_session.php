<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['staff'])) {
    $s = $_SESSION['staff'];
    echo json_encode(['authenticated'=>true,'role'=>$s['role'] ?? '','email'=>$s['email'] ?? '','name'=>$s['name'] ?? '']);
} else {
    echo json_encode(['authenticated'=>false]);
}
