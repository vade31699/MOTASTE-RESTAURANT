<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

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

    if ((int)$file['size'] > MAX_UPLOAD_BYTES) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Image exceeds the 5 MB size limit']);
        exit;
    }

    // Verify the file is a real image and derive the extension from its actual
    // content rather than trusting the client-supplied filename/extension.
    $imageInfo = @getimagesize((string)$file['tmp_name']);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Uploaded file is not a valid image']);
        exit;
    }

    $mimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $extension = $mimeToExtension[$imageInfo['mime']] ?? null;
    if ($extension === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, GIF, and WebP images are allowed']);
        exit;
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

    $relativeUrl = '/special_food_images/' . $fileName;
    $origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $url = $origin . $relativeUrl;

    echo json_encode([
        'success' => true,
        'url' => $url,
        'path' => $destination,
        'relativeUrl' => $relativeUrl,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Image upload failed', 'details' => apiErrorDetail($error)]);
}
