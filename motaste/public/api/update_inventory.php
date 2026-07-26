<?php
header('Content-Type: application/json');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$input = json_decode(file_get_contents('php://input'), true);
$name = isset($input['name']) ? trim($input['name']) : '';
$price = isset($input['price']) ? (float)$input['price'] : 0;
$stock = isset($input['stock']) ? (int)$input['stock'] : 0;
$category = isset($input['category']) ? trim($input['category']) : 'specials';
$status = isset($input['status']) ? trim($input['status']) : ($stock > 0 ? 'In stock' : 'Out of stock');

$canonicalName = preg_replace('/\s+/', ' ', $name);
$canonicalName = trim((string)$canonicalName);

if ($canonicalName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Item name is required']);
    exit;
}

$normalizedStatus = $stock > 0 ? ($status === 'Out of stock' ? 'In stock' : $status) : 'Out of stock';

try {
    $normalizedLookup = strtolower($canonicalName);

    $existingIds = DB::table('inventory_items')
        ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedLookup])
        ->pluck('id')
        ->all();

    if (!empty($existingIds)) {
        DB::table('inventory_items')
            ->whereIn('id', $existingIds)
            ->update([
                'name' => $canonicalName,
                'price' => $price,
                'stock' => $stock,
                'status' => $normalizedStatus,
                'category' => $category,
                'updated_at' => now(),
            ]);
    } else {
        DB::table('inventory_items')->insert([
            'name' => $canonicalName,
            'price' => $price,
            'stock' => $stock,
            'status' => $normalizedStatus,
            'category' => $category,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $itemId = DB::table('inventory_items')
        ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedLookup])
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->value('id');

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
