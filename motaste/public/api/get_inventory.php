<?php
header('Content-Type: application/json');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $items = DB::table('inventory_items')
        ->select('name', 'price', 'stock', 'status', 'category')
        ->orderBy('id', 'asc')
        ->get()
        ->map(function ($row) {
            $stock = (int)($row->stock ?? 0);
            return [
                'name' => $row->name,
                'price' => (float)($row->price ?? 0),
                'stock' => $stock,
                'status' => $row->status ?: ($stock > 0 ? 'In stock' : 'Out of stock'),
                'category' => $row->category ?: 'specials',
            ];
        })
        ->values()
        ->all();

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
}
