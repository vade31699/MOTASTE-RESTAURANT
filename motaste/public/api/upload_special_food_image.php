<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_helpers.php';
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

    // Cap the upload size (bytes) before inspecting content.
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Image must be between 1 byte and 5MB']);
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

    // Bounds: reject images beyond the edge limit before any decode, so a
    // single upload cannot exhaust the worker's memory even with a small file.
    if ((int) ($imageInfo[0] ?? 0) > MAX_UPLOADED_IMAGE_DIMENSION
        || (int) ($imageInfo[1] ?? 0) > MAX_UPLOADED_IMAGE_DIMENSION) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Image dimensions are too large']);
        exit;
    }

    // Re-encode with GD to strip any polyglot/executable payload riding a valid
    // image header. null means the payload is not actually decodable as a real
    // image of the reported type — reject it rather than store it.
    $rawBytes = (string) file_get_contents($file['tmp_name']);
    $sanitized = @sanitizeStoredImageBytes($rawBytes, (int) $imageInfo[2]);
    if ($sanitized === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Uploaded image could not be decoded safely']);
        exit;
    }

    $fileName = 'special-food-' . time() . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    $publicDirectory = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'special_food_images';
    if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) {
        throw new RuntimeException('Unable to create public image directory');
    }

    $destination = $publicDirectory . DIRECTORY_SEPARATOR . $fileName;
    $written = @file_put_contents($destination, $sanitized, LOCK_EX);
    if ($written === false || $written < 1) {
        throw new RuntimeException('Unable to save uploaded file to public folder');
    }

    $relativeUrl = '/special_food_images/' . $fileName;

    // Build the absolute URL from the configured app URL — never from the
    // client-supplied Host header, which would let an authenticated uploader
    // poison the stored URL (e.g. point the public menu at an external host).
    // The server filesystem path is deliberately not returned (info disclosure).
    $baseUrl = rtrim((string) config('app.url', ''), '/');

    echo json_encode([
        'success' => true,
        'url' => $baseUrl !== '' ? $baseUrl . $relativeUrl : $relativeUrl,
        'relativeUrl' => $relativeUrl,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Image upload failed']);
}
