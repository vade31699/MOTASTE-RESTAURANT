<?php
/**
 * Authorized retrieval of an uploaded special-food image.
 *
 * Files are stored OUTSIDE the web root (storage/app/private/special_food_images)
 * and are never served as static assets. This endpoint is the only way to
 * download them and enforces:
 *
 *   - Authorization: only Admin / Inventory Manager staff may fetch a file.
 *   - Path-traversal defense: the file parameter must exactly match the
 *     server-generated name format, and its canonical path must stay inside
 *     the private storage directory.
 *   - MIME safety: content type is derived from the stored content-through-
 *     extension mapping; nosniff prevents the browser from reclassifying it.
 */
require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_helpers.php';
if (!requireInventoryAuth()) {
    abortStaffAuthRequired();
}

// Strict allowlist of the server-generated filename format. Rejects any
// traversal component ('../', '..\\'), absolute paths, encoded separators,
// or executable extensions before a single filesystem call is made.
$fileName = (string) ($_GET['file'] ?? '');
if (!preg_match('/^special-food-\d+-[a-f0-9]{12}\.(?:jpg|png|gif|webp)$/', $fileName)) {
    http_response_code(404);
    exit;
}

$storageDirectory = storage_path('app/private/special_food_images');
$realStorage = realpath($storageDirectory);
$fullPath = $storageDirectory . DIRECTORY_SEPARATOR . $fileName;
$realPath = realpath($fullPath);

// Defense in depth: even though the format above cannot traverse, verify the
// canonical path still sits inside the private directory before serving.
if ($realStorage === false || $realPath === false || strpos($realPath, $realStorage) !== 0) {
    http_response_code(404);
    exit;
}

if (!is_file($realPath)) {
    http_response_code(404);
    exit;
}

$mimeByExtension = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mime = $mimeByExtension[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($realPath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

@readfile($realPath);
exit;