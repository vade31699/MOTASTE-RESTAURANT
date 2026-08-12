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

require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/_staff_auth_helpers.php';
require_once __DIR__ . '/_helpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

validateCsrfOrExit();

// Rate-limit order creation per IP so the public endpoint cannot be spammed.
if (isOrderApiRateLimited('create_order', ORDER_CREATE_MAX_PER_WINDOW, ORDER_CREATE_WINDOW_SECONDS)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many orders from this device. Please try again in a few minutes.']);
    exit;
}

$orderNumber = trim((string)($input['orderNumber'] ?? ''));
$items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
$paymentMethod = trim((string)($input['paymentMethod'] ?? 'Cash'));
$orderType = trim((string)($input['orderType'] ?? 'Dine In'));
$customerName = trim((string)($input['customerName'] ?? ''));
$deliveryAddress = trim((string)($input['deliveryAddress'] ?? ''));
$customerEmail = trim((string)($input['customerEmail'] ?? ''));
$customerPhone = trim((string)($input['customerPhone'] ?? ''));
$loyaltyPointsRedeemed = max(0, (int)($input['loyaltyPointsRedeemed'] ?? 0));

// Basic cart sanity checks. Prices are NOT taken from the client — the server
// looks every item up in inventory and charges the stored menu price.
if ($items === []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Your cart is empty.']);
    exit;
}

if (count($items) > 50) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Too many items in the cart.']);
    exit;
}

// Load the authoritative menu prices once (normalized name -> price/stock).
$inventoryRows = DB::table('inventory_items')->get(['name', 'price', 'stock', 'status', 'is_available']);
$priceByNormalizedName = [];
foreach ($inventoryRows as $row) {
    $key = normalizeInventoryName((string)($row->name ?? ''));
    if ($key === '' || isset($priceByNormalizedName[$key])) {
        continue;
    }
    $priceByNormalizedName[$key] = [
        'price' => (float) ($row->price ?? 0),
        'stock' => (int) ($row->stock ?? 0),
        'is_available' => !(($row->is_available === false || $row->is_available === 0
            || strtolower((string)($row->is_available ?? '1')) === 'false'
            || strtolower((string)($row->is_available ?? '1')) === '0')),
    ];
}

$normalizedItems = [];
$subtotal = 0.0;
foreach ($items as $index => $it) {
    $name = trim((string)($it['name'] ?? ''));
    $qty = (int)($it['quantity'] ?? 0);

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'One of the cart items is missing a name.']);
        exit;
    }
    if ($qty < 1 || $qty > 99) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Invalid quantity for \"{$name}\"."]);
        exit;
    }

    $key = normalizeInventoryName($name);
    if (!isset($priceByNormalizedName[$key])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "\"{$name}\" is no longer on the menu. Please refresh and try again."]);
        exit;
    }

    $meta = $priceByNormalizedName[$key];
    if (!$meta['is_available'] || $meta['stock'] <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "\"{$name}\" is currently unavailable."]);
        exit;
    }

    $unitPrice = round($meta['price'], 2);
    $components = is_array($it['components'] ?? null) ? array_values($it['components']) : null;
    $lineTotal = round($unitPrice * $qty, 2);
    $subtotal += $lineTotal;

    $normalizedItems[] = [
        'name' => $name,
        'unit_price' => $unitPrice,
        'quantity' => $qty,
        'line_total' => $lineTotal,
        'components' => $components,
    ];
}
$subtotal = round($subtotal, 2);

// Loyalty redemption: validate against the customer's balance BEFORE the order
// is inserted, so the discount amount and stored points are authoritative and
// consistent. The actual point deduction happens after the order is created.
// Discounts can ONLY come from a server-verified loyalty redemption; client
// supplied discount values are ignored (prevents price/discount tampering).
$discountAmount = 0.0;
$loyaltyRedemptionPending = false;
if ($loyaltyPointsRedeemed > 0 && $customerPhone !== '') {
    $loyaltyAccount = getLoyaltyAccount($customerPhone);
    $requiredPoints = $loyaltyPointsRedeemed * LOYALTY_REDEMPTION_POINTS;
    $availablePoints = $loyaltyAccount ? (int)($loyaltyAccount->points ?? 0) : 0;

    if ($loyaltyAccount && $availablePoints >= $requiredPoints) {
        $discountAmount = (float) ($loyaltyPointsRedeemed * LOYALTY_REDEMPTION_VALUE);
        $loyaltyRedemptionPending = true;
    } else {
        // Insufficient points: ignore the redemption request entirely.
        $loyaltyPointsRedeemed = 0;
        $discountAmount = 0.0;
    }
}

$total = max(0, $subtotal - $discountAmount);

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
    $finalOrderNumber = '';
    $maxInsertAttempts = 3;

    for ($attempt = 0; $attempt < $maxInsertAttempts; $attempt++) {
        $finalOrderNumber = ensureUniqueOrderNumber($orderNumber);

        try {
            DB::transaction(function () use (&$orderId, &$insertedItems, $finalOrderNumber, $paymentMethod, $orderType, $customerName, $deliveryAddress, $customerEmail, $customerPhone, $discountAmount, $loyaltyPointsRedeemed, $subtotal, $total, $normalizedItems) {
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => $finalOrderNumber,
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
            'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
            'total_amount' => $total,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($normalizedItems as $item) {
            $components = $item['components'];

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
                'notes' => $item['name'],
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
                'order_number' => $finalOrderNumber,
                'event_type' => 'order_created',
                'order_type' => $orderType,
                'payload' => json_encode(['items' => $normalizedItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $__e) {
            // don't fail order creation for event logging errors
            error_log('order_events insert failed: ' . $__e->getMessage());
        }
        });

            // A concurrent request may have claimed our order number between
            // the uniqueness check and the insert; retry with a fresh number.
            break;
        } catch (Throwable $txError) {
            if ($attempt < $maxInsertAttempts - 1 && isOrderNumberUniqueViolation($txError)) {
                continue;
            }
            throw $txError;
        }
    }

    // Deduct the loyalty points after the order exists (best-effort).
    if ($loyaltyRedemptionPending) {
        try {
            redeemLoyaltyPoints(
                $customerPhone,
                $loyaltyPointsRedeemed,
                $orderId,
                $finalOrderNumber
            );
        } catch (Throwable $redeemError) {
            error_log('loyalty redemption failed: ' . $redeemError->getMessage());
        }
    }

    recordOrderApiRequest('create_order');

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'orderNumber' => $finalOrderNumber,
        'insertedItems' => $insertedItems,
        'discount' => $discountAmount,
        'total' => $total,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert order failed', 'details' => apiErrorDetail($error)]);
}
