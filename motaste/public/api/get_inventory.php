<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

use Illuminate\Support\Facades\DB;

try {
    // Staff scope includes cost/reorder/availability fields and requires the
    // staff session. Public scope hides cost data from the customer menu.
    $scope = strtolower(trim((string)($_GET['scope'] ?? 'public')));
    $isStaffScope = $scope === 'staff';
    if ($isStaffScope) {
        require_once __DIR__ . '/_staff_auth_helpers.php';
        if (!requireStaffAuth()) {
            abortStaffAuthRequired();
        }
        // NOTE: low-stock alert emails are deliberately NOT sent from this read
        // path — the SMTP round-trip would delay every inventory load. They fire
        // from the stock-changing write endpoints instead (mark_order_complete,
        // update_inventory), where the dedup keeps them at one email per item
        // per 6-hour window.
    }

    $rawItems = DB::table('inventory_items')
        ->select('name', 'price', 'stock', 'status', 'category', 'description', 'image', 'unit_cost', 'reorder_level', 'is_available')
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
        $item = [
            'name' => trim((string)$row->name),
            'price' => (float)($row->price ?? 0),
            'stock' => $stock,
            'status' => $row->status ?: ($stock > 0 ? 'In stock' : 'Out of stock'),
            'category' => $row->category ?: 'specials',
            'description' => trim((string)($row->description ?? '')),
            'image' => trim((string)($row->image ?? '')),
            'is_available' => !(($row->is_available === false || $row->is_available === 0 || strtolower((string)($row->is_available ?? '1')) === 'false' || strtolower((string)($row->is_available ?? '1')) === '0')),
        ];
        if ($isStaffScope) {
            $item['unit_cost'] = (float)($row->unit_cost ?? 0);
            $item['reorder_level'] = (int)($row->reorder_level ?? 0);
        }
        $itemsByName[$normalizedName] = $item;
    }

    $items = array_values($itemsByName);

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
}
