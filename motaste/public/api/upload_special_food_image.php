<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;

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
    $path = 'special_food_images/' . $fileName;
    $contents = file_get_contents($file['tmp_name']);
    if ($contents === false) {
        throw new RuntimeException('Unable to read uploaded file contents');
    }

    $stored = Storage::disk('public')->put($path, $contents, 'public');
    if ($stored === false) {
        throw new RuntimeException('Unable to save uploaded file to storage');
    }

    $url = Storage::disk('public')->url($path);

    echo json_encode([
        'success' => true,
        'url' => $url,
        'path' => $path,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Image upload failed', 'details' => $error->getMessage()]);
}
