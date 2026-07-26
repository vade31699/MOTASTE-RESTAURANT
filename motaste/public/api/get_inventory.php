<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $rawItems = DB::table('inventory_items')
        ->select('name', 'price', 'stock', 'status', 'category')
        ->orderBy('updated_at', 'desc')
        ->orderBy('id', 'desc')
        ->get()
        ->all();

    $itemsByName = [];
    foreach ($rawItems as $row) {
        $normalizedName = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$row->name)));
        if ($normalizedName === '' || isset($itemsByName[$normalizedName])) {
            continue;
        }

        $stock = (int)($row->stock ?? 0);
        $itemsByName[$normalizedName] = [
            'name' => trim((string)$row->name),
            'price' => (float)($row->price ?? 0),
            'stock' => $stock,
            'status' => $row->status ?: ($stock > 0 ? 'In stock' : 'Out of stock'),
            'category' => $row->category ?: 'specials',
        ];
    }

    $items = array_values($itemsByName);

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
}
