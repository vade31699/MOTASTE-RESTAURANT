<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

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

    $normalizedName = strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));

    $existing = DB::table('inventory_items')
        ->whereRaw("LOWER(REGEXP_REPLACE(TRIM(name), '\\s+', ' ', 'g')) = ?", [$normalizedName])
        ->first();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Inventory item not found']);
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

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to delete inventory item', 'details' => $error->getMessage()]);
}