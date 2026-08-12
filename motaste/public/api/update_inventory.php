<?php
header('Content-Type: application/json');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__ . '/_staff_auth_helpers.php';
if (!requireStaffAuth()) {
    abortStaffAuthRequired();
}

require_once __DIR__ . '/csrf_guard.php';
validateCsrfOrExit();



require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;

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
$name = isset($input['name']) ? trim($input['name']) : '';
$previousName = isset($input['previousName']) ? trim((string)$input['previousName']) : '';
$price = isset($input['price']) ? (float)$input['price'] : 0;
$stock = isset($input['stock']) ? (int)$input['stock'] : 0;
$category = isset($input['category']) ? trim($input['category']) : 'specials';
$description = isset($input['description']) ? trim((string)$input['description']) : '';
$status = isset($input['status']) ? trim($input['status']) : ($stock > 0 ? 'In stock' : 'Out of stock');
$unitCost = isset($input['unitCost']) ? (float)$input['unitCost'] : 0;
$reorderLevel = isset($input['reorderLevel']) ? (int)$input['reorderLevel'] : 0;
$isAvailable = isset($input['isAvailable']) ? (($input['isAvailable'] === true || $input['isAvailable'] === 'true' || $input['isAvailable'] === 1 || $input['isAvailable'] === '1') ? 1 : 0) : 1;
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

$canonicalName = preg_replace('/\s+/', ' ', $name);
$canonicalName = trim((string)$canonicalName);

if ($canonicalName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Item name is required']);
    exit;
}

$blockedNames = ['softdrinks'];
if (in_array(strtolower($canonicalName), $blockedNames, true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Softdrinks is no longer allowed in inventory']);
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

    $image = isset($input['image']) ? trim((string)$input['image']) : null;

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

    echo json_encode([
        'success' => true,
        'itemId' => $itemId,
        'stock' => $stock,
        'status' => $normalizedStatus,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database update failed', 'details' => apiErrorDetail($error)]);
}
