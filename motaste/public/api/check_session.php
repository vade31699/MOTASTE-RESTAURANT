<?php
header('Content-Type: application/json');

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 30 * 24 * 60 * 60,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (isset($_SESSION['staff'])) {
    $s = $_SESSION['staff'];
    echo json_encode(['authenticated'=>true,'role'=>$s['role'] ?? '','email'=>$s['email'] ?? '','name'=>$s['name'] ?? '']);
} else {
    echo json_encode(['authenticated'=>false]);
}
