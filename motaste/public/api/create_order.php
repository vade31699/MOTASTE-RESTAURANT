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
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

// Provides IP-based rate limiting (recordOrderApiRequest / isOrderApiRateLimited).
require_once __DIR__ . '/_staff_auth_helpers.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input['items'] ?? null) || count($input['items']) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON or empty order']);
    exit;
}

// Per-IP abuse protection for order creation: count only valid attempts so a
// scripted spammer is throttled while a customer placing a few orders is not.
recordOrderApiRequest('create_order');
if (isOrderApiRateLimited('create_order', 12, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many orders placed from this device. Please wait a minute and try again.']);
    exit;
}

// Order numbers are only ever shown to staff/customers; restrict them to
// digits so they cannot smuggle markup into the staff dashboard (stored XSS).
$orderNumber = trim((string)($input['orderNumber'] ?? ''));
if (!preg_match('/^\d{4,20}$/', $orderNumber)) {
    $orderNumber = (string)time();
}

$items = is_array($input['items'] ?? null) ? $input['items'] : [];
$paymentMethod = trim((string)($input['paymentMethod'] ?? 'Cash'));
$orderType = trim((string)($input['orderType'] ?? 'Dine In'));
$customerName = trim((string)($input['customerName'] ?? ''));
$deliveryAddress = trim((string)($input['deliveryAddress'] ?? ''));
$customerEmail = trim((string)($input['customerEmail'] ?? ''));
$customerPhone = trim((string)($input['customerPhone'] ?? ''));
// A client-supplied discount is never trusted; only server-computed
// adjustments are applied (none remain now that loyalty is removed).
$discountAmount = 0;

// Resolve catalog prices once so line totals use server-authoritative prices
// instead of whatever the client sent (price tampering protection). If the
// catalog is unavailable (fresh deployment), fall back to submitted prices.
require_once __DIR__ . '/_helpers.php';
$inventoryPriceMap = [];
try {
    foreach (DB::table('inventory_items')->get(['name', 'price']) as $row) {
        $inventoryPriceMap[normalizeInventoryName((string)$row->name)] = (float)($row->price ?? 0);
    }
} catch (Throwable $priceLookupError) {
    $inventoryPriceMap = [];
}

function resolveCatalogPrice(string $itemName, float $clientPrice, array $inventoryPriceMap): float
{
    $normalizedName = normalizeInventoryName($itemName);
    if ($normalizedName !== '' && isset($inventoryPriceMap[$normalizedName])) {
        return (float)$inventoryPriceMap[$normalizedName];
    }
    // Unrecognized/legacy menu entries: fall back to the submitted price.
    return $clientPrice;
}

$subtotal = 0;
foreach ($items as $it) {
    $itemName = trim((string)($it['name'] ?? 'Menu item'));
    $price = resolveCatalogPrice($itemName, (float)($it['price'] ?? 0), $inventoryPriceMap);
    $subtotal += $price * max(0, (int)($it['quantity'] ?? 0));
}
$total = $subtotal;

try {
    // Ensure customer detail columns exist (schema managed by migrations; this
    // keeps order creation working even if migrations have not been run yet).
    try {
        if (!Schema::hasColumn('orders', 'customer_name')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('customer_name', 191)->nullable()->after('order_type');
            });
        }
        if (!Schema::hasColumn('orders', 'delivery_address')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('delivery_address')->nullable()->after('customer_name');
            });
        }
    } catch (Throwable $__schemaError) {
        // Schema changes must never block order creation; the migration will
        // apply the columns on deploy.
        error_log('orders customer columns check failed: ' . $__schemaError->getMessage());
    }

    $orderId = null;
    $insertedItems = 0;

    DB::transaction(function () use (&$orderId, &$insertedItems, $orderNumber, $paymentMethod, $orderType, $customerName, $deliveryAddress, $customerEmail, $customerPhone, $discountAmount, $subtotal, $total, $items, $inventoryPriceMap) {
        $now = now();
        $finalTotal = max(0, $total - $discountAmount);
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => $orderNumber,
            'order_date' => $now,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => $paymentMethod,
            'order_type' => $orderType,
            'customer_name' => $customerName !== '' ? $customerName : null,
            'delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            'subtotal' => $subtotal,
            'discount' => $discountAmount,
            'total_amount' => $finalTotal,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($items as $it) {
            $itemName = trim((string)($it['name'] ?? 'Menu item'));
            $price = resolveCatalogPrice($itemName, (float)($it['price'] ?? 0), $inventoryPriceMap);
            $qty = max(0, (int)($it['quantity'] ?? 0));
            $lineTotal = $price * $qty;
            $components = is_array($it['components'] ?? null) ? array_values($it['components']) : null;

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'quantity' => $qty,
                'unit_price' => $price,
                'line_total' => $lineTotal,
                'notes' => $itemName,
                'components' => $components !== null ? json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $insertedItems++;
        }

        // Insert lightweight event record for real-time clients
        try {
            if (!Schema::hasTable('order_events')) {
                Schema::create('order_events', function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->unsignedBigInteger('order_id')->nullable()->index();
                    $table->string('order_number')->nullable()->index();
                    $table->string('event_type', 64)->index();
                    $table->string('order_type', 64)->nullable()->index();
                    $table->text('payload')->nullable();
                    $table->timestamps();
                });
            }

            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'event_type' => 'order_created',
                'order_type' => $orderType,
                'payload' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $__e) {
            // don't fail order creation for event logging errors
            error_log('order_events insert failed: ' . $__e->getMessage());
        }
    });

    echo json_encode(['success' => true, 'orderId' => $orderId, 'insertedItems' => $insertedItems, 'discount' => $discountAmount]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert order failed']);
}
