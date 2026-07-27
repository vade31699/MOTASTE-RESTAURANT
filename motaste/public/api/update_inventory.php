<?php
header('Content-Type: application/json');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

function ensureOrderLogsTable(): void
{
    DB::statement("CREATE TABLE IF NOT EXISTS order_activity_logs (
        id BIGSERIAL PRIMARY KEY,
        order_id BIGINT NULL,
        order_number VARCHAR(191) NULL,
        action VARCHAR(100) NOT NULL,
        actor_role VARCHAR(100) NULL,
        actor_email VARCHAR(191) NULL,
        summary TEXT NULL,
        details TEXT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
}

function ensureInventoryTable(): void
{
    // Create inventory_items table if it doesn't exist
    DB::statement("CREATE TABLE IF NOT EXISTS inventory_items (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(191) NOT NULL UNIQUE,
        price DECIMAL(10, 2) NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        status VARCHAR(100) NOT NULL DEFAULT 'In stock',
        category VARCHAR(100) NOT NULL DEFAULT 'specials',
        description TEXT,
        is_addon BOOLEAN NOT NULL DEFAULT FALSE,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
    
    // Add missing columns if they don't exist (PostgreSQL syntax)
    try {
        DB::statement("ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS description TEXT");
    } catch (Throwable $e) {
        // Column already exists, safe to ignore
    }
    
    try {
        DB::statement("ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS is_addon BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (Throwable $e) {
        // Column already exists, safe to ignore
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$name = isset($input['name']) ? trim($input['name']) : '';
$previousName = isset($input['previousName']) ? trim((string)$input['previousName']) : '';
$price = isset($input['price']) ? (float)$input['price'] : 0;
$stock = isset($input['stock']) ? (int)$input['stock'] : 0;
$category = isset($input['category']) ? trim($input['category']) : 'specials';
$description = isset($input['description']) ? trim((string)$input['description']) : '';
$isAddon = isset($input['isAddon']) ? (bool)$input['isAddon'] : false;
$status = isset($input['status']) ? trim($input['status']) : ($stock > 0 ? 'In stock' : 'Out of stock');
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
    ensureInventoryTable();

    $normalizedLookup = strtolower($canonicalName);
    $normalizedPrevious = strtolower(trim((string)preg_replace('/\s+/', ' ', $previousName)));

    $existingBefore = null;
    if ($normalizedPrevious !== '') {
        $existingBefore = DB::table('inventory_items')
            ->whereRaw('LOWER(name) = ?', [$normalizedPrevious])
            ->first();
    }

    if (!$existingBefore) {
        $existingBefore = DB::table('inventory_items')
            ->whereRaw('LOWER(name) = ?', [$normalizedLookup])
            ->first();
    }

    DB::transaction(function () use ($normalizedLookup, $normalizedPrevious, $canonicalName, $price, $stock, $normalizedStatus, $category, $description, $isAddon, $existingBefore) {
        if ($existingBefore) {
            // Update existing item
            DB::table('inventory_items')
                ->where('id', $existingBefore->id)
                ->update([
                    'name' => $canonicalName,
                    'price' => $price,
                    'stock' => $stock,
                    'status' => $normalizedStatus,
                    'category' => $category,
                    'description' => $description,
                    'is_addon' => $isAddon,
                    'updated_at' => Carbon::now(),
                ]);
            
            // Delete any other items with the normalized name (in case of duplicates)
            DB::table('inventory_items')
                ->where('id', '!=', $existingBefore->id)
                ->whereRaw('LOWER(name) = ?', [$normalizedLookup])
                ->delete();
        } else {
            // New item - delete any existing with same normalized name first
            DB::table('inventory_items')
                ->whereRaw('LOWER(name) = ?', [$normalizedLookup])
                ->delete();

            // Then insert
            DB::table('inventory_items')->insert([
                'name' => $canonicalName,
                'price' => $price,
                'stock' => $stock,
                'status' => $normalizedStatus,
                'category' => $category,
                'description' => $description,
                'is_addon' => $isAddon,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
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
            'status' => $normalizedStatus,
            'description' => $description,
            'is_addon' => $isAddon,
            'previous_name' => $existingBefore ? $existingBefore->name : null,
            'previous_stock' => $existingBefore ? (int)($existingBefore->stock ?? 0) : null,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    $itemId = DB::table('inventory_items')
        ->whereRaw('LOWER(name) = ?', [$normalizedLookup])
        ->value('id');

    echo json_encode([
        'success' => true,
        'itemId' => $itemId,
        'stock' => $stock,
        'status' => $normalizedStatus,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database update failed', 'details' => $error->getMessage()]);
}
