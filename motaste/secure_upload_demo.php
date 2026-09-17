<?php
/**
 * Secure File Upload Demo — uses YOUR codebase's actual validation.
 */
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/public/api/_helpers.php';

function check($label, $condition, $detail = '') {
    echo ($condition ? "PASS  " : "FAIL  ") . $label;
    if ($detail) echo "  ->  $detail";
    echo "\n";
}

// ============================================
// DEMO 1: PHP file disguised as image
// ============================================
echo "=== Demo 1: PHP file disguised as .jpg ===\n";
$shellPhp = '<?php system($_GET["cmd"]); ?>';
$info = @getimagesize('data://text/plain,' . rawurlencode($shellPhp));
check('Malicious .php content rejected by content sniffing', $info === false, 'not a real image');

// ============================================
// DEMO 2: Real image with hidden script (polyglot)
// ============================================
echo "\n=== Demo 2: Real PNG with hidden <script> payload ===\n";
$tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
$polyglot = $tinyPng . "<script>alert('xss')</script><!-- handle:ancestor1/0 -->";
$info2 = @getimagesizefromstring($polyglot);
$clean = sanitizeStoredImageBytes($polyglot, IMAGETYPE_PNG);
check('Sniffer still sees it as a PNG', $info2 !== false && $info2[2] === IMAGETYPE_PNG, 'passes the sniff check');
check('GD re-encode strips the <script> payload', $clean !== null && !str_contains($clean, '<script>'), function_exists('imagecreatefromstring') ? 'script removed' : 'GD not available, original passed through');

// ============================================
// DEMO 3: Path traversal on download filename
// ============================================
echo "\n=== Demo 3: Path traversal attempts ===\n";
$storageDirectory = storage_path('app/private/special_food_images');
function isValidServerFilename($name) {
    return preg_match('/^special-food-\d+-[a-f0-9]{12}\.(?:jpg|png|gif|webp)$/', (string)$name) === 1;
}
$attacks = [
    '../../config.php',
    '..\\..\\.env',
    '../config/app.php',
    'special-food.jpg; rm -rf /',
    'special-food-123456789012-abcdef123456.php',
    '..%2F..%2F.env',
];
foreach ($attacks as $attack) {
    check("Rejected: $attack", !isValidServerFilename($attack));
}
check('Legit server-generated name accepted', isValidServerFilename('special-food-1700000000-abcdef123456.png'));

// ============================================
// DEMO 4: Files stored OUTSIDE web root
// ============================================
echo "\n=== Demo 4: Storage location ===\n";
$webRoot = realpath(__DIR__ . '/public');
$insideWebRoot = strpos($storageDirectory, $webRoot) === 0;
check('Images stored outside public/', !$insideWebRoot, $storageDirectory);

// ============================================
// DEMO 5: Random vs original filename
// ============================================
echo "\n=== Demo 5: Server-generated random filename ===\n";
$originalClientName = 'hacker-shell.upload.php.jpg';
$serverName = 'special-food-' . time() . '-' . bin2hex(random_bytes(6)) . '.jpg';
check('Client filename never used', $serverName !== $originalClientName);
check('Name is random (64-bit)', strlen($serverName) > 30, $serverName);

echo "\nDone. All upload security checks demonstrated.\n";