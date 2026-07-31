<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No image file uploaded']);
        exit;
    }

    $file = $_FILES['image'];
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid uploaded file']);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Upload error: ' . $file['error']]);
        exit;
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        $extension = 'jpg';
    }

    $fileName = 'special-food-' . time() . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    $publicDirectory = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'special_food_images';
    if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) {
        throw new RuntimeException('Unable to create public image directory');
    }

    $destination = $publicDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save uploaded file to public folder');
    }

    $url = '/special_food_images/' . $fileName;

    echo json_encode([
        'success' => true,
        'url' => $url,
        'path' => $destination,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Image upload failed', 'details' => $error->getMessage()]);
}
