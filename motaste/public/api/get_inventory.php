<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

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
        image LONGTEXT,
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
        DB::statement("ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS image LONGTEXT");
    } catch (Throwable $e) {
        // Column already exists, safe to ignore
    }
    
    try {
        DB::statement("ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS is_addon BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (Throwable $e) {
        // Column already exists, safe to ignore
    }
}

try {
    ensureInventoryTable();
    DB::table('inventory_items')
        ->whereRaw('LOWER(name) = ?', ['softdrinks'])
        ->delete();

    $rawItems = DB::table('inventory_items')
        ->select('name', 'price', 'stock', 'status', 'category', 'description', 'image', 'is_addon')
        ->orderBy('updated_at', 'desc')
        ->orderBy('id', 'desc')
        ->get()
        ->all();

    $itemsByName = [];
    foreach ($rawItems as $row) {
        $normalizedName = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$row->name)));
        if ($normalizedName === '' || $normalizedName === 'softdrinks' || isset($itemsByName[$normalizedName])) {
            continue;
        }

        $stock = (int)($row->stock ?? 0);
        $itemsByName[$normalizedName] = [
            'name' => trim((string)$row->name),
            'price' => (float)($row->price ?? 0),
            'stock' => $stock,
            'status' => $row->status ?: ($stock > 0 ? 'In stock' : 'Out of stock'),
            'category' => $row->category ?: 'specials',
            'description' => $row->description ?: '',
            'image' => $row->image ?: '',
            'is_addon' => (bool)($row->is_addon ?? false),
        ];
    }

    $items = array_values($itemsByName);

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
}
