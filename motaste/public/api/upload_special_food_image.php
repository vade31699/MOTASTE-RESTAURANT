<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireInventoryAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();

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

    // Validate the file is a real image and derive the extension from its
    // actual content (never from the client-supplied filename), so a file
    // disguised as an image is rejected before it is stored.
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || !isset($imageInfo[2])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Uploaded file is not a valid image']);
        exit;
    }
    $mimeToExtension = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $extension = $mimeToExtension[$imageInfo[2]] ?? null;
    if ($extension === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unsupported image type']);
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
    echo json_encode(['success' => false, 'error' => 'Image upload failed']);
}
