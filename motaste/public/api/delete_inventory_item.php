<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;

function ensureOrderLogsTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

function removeFromCustomMenuSnapshot(string $normalizedName): bool
{
    if ($normalizedName === '') {
        return false;
    }

    
    $snapshotRow = DB::table('custom_menu_snapshots')
        ->where('snapshot_key', 'motaste-menu')
        ->first();

    if (!$snapshotRow || !isset($snapshotRow->snapshot_payload)) {
        return false;
    }

    $payload = json_decode((string)$snapshotRow->snapshot_payload, true);
    if (!is_array($payload)) {
        return false;
    }

    $removed = false;

    if (isset($payload['specialFoods']) && is_array($payload['specialFoods'])) {
        $before = count($payload['specialFoods']);
        $payload['specialFoods'] = array_values(array_filter($payload['specialFoods'], function ($food) use ($normalizedName) {
            $candidate = normalizeItemName((string)($food['name'] ?? ''));
            return $candidate !== $normalizedName;
        }));

        if (count($payload['specialFoods']) !== $before) {
            $removed = true;
        }
    }

    if (isset($payload['menuData']) && is_array($payload['menuData'])) {
        foreach ($payload['menuData'] as $categoryKey => $category) {
            if (!is_array($category) || !isset($category['items']) || !is_array($category['items'])) {
                continue;
            }

            $before = count($category['items']);
            $category['items'] = array_values(array_filter($category['items'], function ($item) use ($normalizedName) {
                $candidate = normalizeItemName((string)($item['name'] ?? ''));
                return $candidate !== $normalizedName;
            }));

            if (count($category['items']) !== $before) {
                $removed = true;
            }

            $payload['menuData'][$categoryKey] = $category;
        }
    }

    if ($removed) {
        DB::table('custom_menu_snapshots')
            ->where('snapshot_key', 'motaste-menu')
            ->update([
                'snapshot_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    return $removed;
}

$input = json_decode(file_get_contents('php://input'), true);
$name = trim((string)($input['name'] ?? ''));
$actorRole = trim((string)($input['actorRole'] ?? 'Staff'));
$actorEmail = trim((string)($input['actorEmail'] ?? ''));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'name is required']);
    exit;
}

try {
    ensureOrderLogsTable();

    $normalizedName = normalizeItemName($name);

    $existing = DB::table('inventory_items')
        ->whereRaw("LOWER(REGEXP_REPLACE(TRIM(name), '\\s+', ' ', 'g')) = ?", [$normalizedName])
        ->first();

    $removedFromSnapshot = removeFromCustomMenuSnapshot($normalizedName);

    if (!$existing) {
        // Keep delete idempotent: special items may exist only in custom menu snapshot.
        echo json_encode([
            'success' => true,
            'deletedFromInventory' => false,
            'deletedFromSnapshot' => $removedFromSnapshot,
        ]);
        exit;
    }

    DB::table('inventory_items')
        ->whereRaw("LOWER(REGEXP_REPLACE(TRIM(name), '\\s+', ' ', 'g')) = ?", [$normalizedName])
        ->delete();

    DB::table('order_activity_logs')->insert([
        'order_id' => null,
        'order_number' => null,
        'action' => 'inventory_item_removed',
        'actor_role' => $actorRole !== '' ? $actorRole : 'Staff',
        'actor_email' => $actorEmail !== '' ? $actorEmail : null,
        'summary' => trim((string)$existing->name),
        'details' => json_encode([
            'name' => trim((string)$existing->name),
            'stock' => (int)($existing->stock ?? 0),
            'price' => (float)($existing->price ?? 0),
            'removed_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode([
        'success' => true,
        'deletedFromInventory' => true,
        'deletedFromSnapshot' => $removedFromSnapshot,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to delete inventory item', 'details' => $error->getMessage()]);
}