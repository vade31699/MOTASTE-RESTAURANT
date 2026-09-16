<?php
header('Content-Type: application/json');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireInventoryAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();



require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

function ensureOrderLogsTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

function findInventoryItemByNormalizedName(string $normalizedName): ?object
{
    $items = DB::table('inventory_items')->select('id', 'name', 'stock', 'status')->get();
    foreach ($items as $item) {
        if (normalizeInventoryName((string)($item->name ?? '')) === $normalizedName) {
            return $item;
        }
    }

    return null;
}

function findInventoryItemIdsByNormalizedNames(array $normalizedNames): array
{
    $ids = [];
    $items = DB::table('inventory_items')->select('id', 'name')->get();
    foreach ($items as $item) {
        $normalized = normalizeInventoryName((string)($item->name ?? ''));
        if (in_array($normalized, $normalizedNames, true)) {
            $ids[] = (int)($item->id ?? 0);
        }
    }
    return array_values(array_unique($ids));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
$name = is_string($input['name'] ?? null) ? trim($input['name']) : null;
$previousName = is_string($input['previousName'] ?? null) ? trim($input['previousName']) : '';
$price = isset($input['price']) ? (float)$input['price'] : 0;
$stock = isset($input['stock']) ? (int)$input['stock'] : 0;
$category = is_string($input['category'] ?? null) ? trim($input['category']) : 'specials';
$description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
$status = is_string($input['status'] ?? null) ? trim($input['status']) : ($stock > 0 ? 'In stock' : 'Out of stock');
$unitCost = isset($input['unitCost']) ? (float)$input['unitCost'] : 0;
$reorderLevel = isset($input['reorderLevel']) ? (int)$input['reorderLevel'] : 0;
$isAvailable = isset($input['isAvailable']) ? (($input['isAvailable'] === true || $input['isAvailable'] === 'true' || $input['isAvailable'] === 1 || $input['isAvailable'] === '1') ? 1 : 0) : 1;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

// Stored-XSS guard: reject HTML/script content in free-text fields.
rejectUnsafeInputOrExit($name, $previousName, $category, $description, $status, $input['image'] ?? null, $actorRole, $actorEmail);

$image = null;
if (array_key_exists('image', $input)) {
    if ($input['image'] !== null && !is_string($input['image'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Image must be a string']);
        exit;
    }
    $image = trim((string)$input['image']);
    if ($image !== '') {
        $imageError = storedImageValidationError($image);
        if ($imageError !== null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $imageError]);
            exit;
        }
    }
}

// Data-type checks: these fields are always sent by the client as booleans/
// numbers; a crafted string here previously fell through the (float)/(int)
// casts silently.
if ($name === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'name must be a string']);
    exit;
}
if (!is_numeric($input['price'] ?? null) || (float)$input['price'] < 0 || (float)$input['price'] > 9999999.99) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'price must be between 0 and 9999999.99']);
    exit;
}
if (!is_numeric($input['stock'] ?? null) || (int)$input['stock'] < 0 || (int)$input['stock'] > 999999) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'stock must be between 0 and 999999']);
    exit;
}
// unitCost/reorderLevel were previously cast with (float)/(int) only, so a
// non-numeric string silently coerced to 0 and passed the range check. Only
// validate when the client actually sends them (older callers omit them).
if (array_key_exists('unitCost', $input) && (!is_numeric($input['unitCost']) || (float)$input['unitCost'] < 0 || (float)$input['unitCost'] > 9999999.99)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'unitCost must be between 0 and 9999999.99']);
    exit;
}
if (array_key_exists('reorderLevel', $input) && (!is_numeric($input['reorderLevel']) || (int)$input['reorderLevel'] < 0 || (int)$input['reorderLevel'] > 999999)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'reorderLevel must be between 0 and 999999']);
    exit;
}

// Length caps matching the inventory_items column widths.
if (mb_strlen($name) > 191 || mb_strlen($previousName) > 191) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Item name is too long']);
    exit;
}
if (mb_strlen($category) > 255) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Category is too long']);
    exit;
}
if (mb_strlen($description) > 5000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Description is too long']);
    exit;
}

// Enumerated category/status values (mirrors the staff.html selects); any
// other value would only ever come from a hand-crafted request.
if (!in_array($category, ['batchoy', 'silog', 'friedChicken', 'breakfast', 'drinks', 'addons', 'specials'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid category']);
    exit;
}
if (!in_array($status, ['In stock', 'Out of stock'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}
if ($unitCost < 0 || $unitCost > 9999999.99 || $reorderLevel < 0 || $reorderLevel > 999999) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unit cost or reorder level is out of range']);
    exit;
}

// Actor identity bounds matching the order_activity_logs column widths.
if (mb_strlen($actorRole) > 100 || mb_strlen($actorEmail) > 191) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Actor details are too long']);
    exit;
}
if ($actorRole !== '' && $actorRole !== 'Staff' && !in_array($actorRole, ['Admin', 'Cashier', 'Inventory Manager'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid actor role']);
    exit;
}
if ($actorEmail !== '' && !filter_var($actorEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid actor email']);
    exit;
}

$canonicalName = preg_replace('/\s+/', ' ', $name);
$canonicalName = trim((string)$canonicalName);

if ($canonicalName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Item name is required']);
    exit;
}

$normalizedStatus = $stock > 0 ? ($status === 'Out of stock' ? 'In stock' : $status) : 'Out of stock';

try {
    ensureOrderLogsTable();

    $normalizedLookup = normalizeInventoryName($canonicalName);
    $normalizedPrevious = normalizeInventoryName($previousName);

    $existingBefore = null;
    if ($normalizedPrevious !== '') {
        $existingBefore = findInventoryItemByNormalizedName($normalizedPrevious);
    }

    if (!$existingBefore) {
        $existingBefore = findInventoryItemByNormalizedName($normalizedLookup);
    }

    $itemId = null;
    DB::transaction(function () use ($normalizedLookup, $normalizedPrevious, $canonicalName, $price, $stock, $normalizedStatus, $category, $description, $image, $unitCost, $reorderLevel, $isAvailable, &$itemId) {
        $deleteIds = findInventoryItemIdsByNormalizedNames(array_filter([$normalizedLookup, $normalizedPrevious]));
        if (!empty($deleteIds)) {
            DB::table('inventory_items')->whereIn('id', $deleteIds)->delete();
        }

        $itemId = DB::table('inventory_items')->insertGetId([
            'name' => $canonicalName,
            'price' => $price,
            'stock' => $stock,
            'status' => $normalizedStatus,
            'category' => $category,
            'description' => $description !== '' ? $description : null,
            'image' => $image !== '' ? $image : null,
            'unit_cost' => max(0, $unitCost),
            'reorder_level' => max(0, $reorderLevel),
            'is_available' => $isAvailable,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $action = 'inventory_item_added';
    if ($existingBefore) {
        $previousStock = (int)($existingBefore->stock ?? 0);
        $action = $previousStock !== $stock ? 'inventory_stock_changed' : 'inventory_item_updated';
    }

    DB::table('order_activity_logs')->insert([
        'order_id' => null,
        'order_number' => null,
        'action' => $action,
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => $canonicalName . ' x' . $stock,
        'details' => json_encode([
            'name' => $canonicalName,
            'stock' => $stock,
            'price' => $price,
            'category' => $category,
            'description' => $description,
            'status' => $normalizedStatus,
            'previous_name' => $existingBefore ? $existingBefore->name : null,
            'previous_stock' => $existingBefore ? (int)($existingBefore->stock ?? 0) : null,
            'updated_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Stock just changed, so this is the right moment to fire any low-stock
    // alert email (deduped to once per item per 6-hour window). This runs only
    // on an admin save action, never on an inventory page load.
    notifyLowStockAlerts();

    // Drop the short-lived inventory cache so the next page load sees the new
    // stock immediately instead of waiting out the TTL.
    try {
        Cache::forget('inventory_staff_v1');
        Cache::forget('inventory_public_v1');
    } catch (Throwable $cacheError) {
        error_log('inventory cache invalidate failed: ' . $cacheError->getMessage());
    }

    echo json_encode([
        'success' => true,
        'itemId' => $itemId,
        'stock' => $stock,
        'status' => $normalizedStatus,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database update failed']);
}
