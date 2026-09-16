<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SQL Injection prevention tests (security requirement 3.2).
 *
 * These tests exercise the exact query-builder patterns the public API
 * endpoints use (bound whereRaw with '?' placeholders, whereIn/whereNotIn of
 * plain value arrays, static groupBy/selectRaw, and the strict whole-number
 * ID guard) and prove that attacker-shaped values are treated strictly as
 * literals by the underlying PDO prepared-statement layer.
 */
function bootSqlInjectionTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database (same strategy as the other
    // Unit tests): never touch the production database.
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../public/api/_helpers.php';
    require_once __DIR__ . '/../../public/api/_staff_auth_helpers.php';

    // Purpose-built tables (unique names so they cannot collide with tables
    // other test files boot during the same run).
    if (!Schema::hasTable('sqli_staff')) {
        Schema::create('sqli_staff', function (Blueprint $table) {
            $table->increments('id');
            $table->string('full_name', 191);
            $table->string('email', 191);
            $table->string('role', 100)->default('Staff');
            $table->string('password_hash', 191)->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('sqli_orders')) {
        Schema::create('sqli_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('order_number', 191)->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('sqli_order_items')) {
        Schema::create('sqli_order_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id');
            $table->string('notes', 191)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('line_total', 10, 2)->default(0);
        });
    }
}

beforeAll(function () {
    bootSqlInjectionTestApp();
});

/**
 * Attack strings that would break out of single quotes, inject OR clauses,
 * terminate statements, or run stacked queries if they were ever interpolated
 * into SQL instead of being bound.
 */
function sqliPayloads(): array
{
    return [
        "admin' OR '1'='1",
        "x@example.com' OR '1'='1' --",
        "a@example.com') OR (1=1) --",
        "'; DROP TABLE sqli_staff; --",
        "1 union select full_name from sqli_staff --",
        '%\' OR \'1\'=\'1',
        '\\" OR true--',
        "normal@example.com'; UPDATE sqli_staff SET role='Admin' WHERE '1'='1",
    ];
}

test('bound whereRaw: SQL payloads never widen the predicate', function () {
    bootSqlInjectionTestApp();

    $payloads = sqliPayloads();
    foreach ($payloads as $i => $payload) {
        DB::table('sqli_staff')->insert([
            'full_name' => 'staff-' . $i,
            'email' => $payload,
            'role' => $i % 2 === 0 ? 'Admin' : 'Staff',
            'password_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $total = DB::table('sqli_staff')->count();
    expect($total)->toBe(count($payloads));

    // The app-wide login/account lookup pattern is
    // whereRaw('LOWER(email) = ?', [$email]). A payload email must NOT return
    // every row.
    foreach ($payloads as $payload) {
        $rows = DB::table('sqli_staff')->whereRaw('LOWER(email) = ?', [strtolower($payload)])->get();
        expect($rows)->toHaveCount(1);
        // And the statement did not modify/delete any unrelated rows.
        expect(DB::table('sqli_staff')->count())->toBe($total);
    }

    // A fresh non-existent email that embeds a tautology matches nothing.
    $none = DB::table('sqli_staff')->whereRaw('LOWER(email) = ?', ["nobody@example.com' OR '1'='1'"])->count();
    expect($none)->toBe(0);
});

test('bound whereNotIn: payload emails are excluded only as literals', function () {
    bootSqlInjectionTestApp();

    // The app stores emails lowercased and matches them lowercased
    // (strtolower on write, LOWER(email) on read), so the risk shape for an
    // email field is an all-lowercase payload.
    $clean = 'clean-' . uniqid() . '@example.com';
    $payload = "victim@example.com' or '1'='1' --";

    DB::table('sqli_staff')->insert(['full_name' => 'A', 'email' => $clean, 'role' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sqli_staff')->insert(['full_name' => 'B', 'email' => $payload, 'role' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);

    // Mirrors _email_auth_helpers.php: whereNotIn(DB::raw('LOWER(email)'), $emails)
    // where $emails was already normalized to lowercase on write.
    $excluded = [$clean, $payload];
    $remaining = DB::table('sqli_staff')->whereNotIn(DB::raw('LOWER(email)'), $excluded)->pluck('email')->all();

    // Exactly the rows NOT listed (the listed ones, including the payload,
    // were excluded as literal values — the payload string was never SQL).
    expect($remaining)->not->toContain($payload);
    expect($remaining)->not->toContain($clean);
    expect($remaining)->not->toContain('victim@example.com');
});

test('static groupBy/selectRaw aggregates are not affected by stored payloads', function () {
    bootSqlInjectionTestApp();

    $signature = "'; DROP TABLE sqli_orders; --";
    $orderId = DB::table('sqli_orders')->insertGetId(['order_number' => 'ORD-1', 'order_date' => now(), 'created_at' => now(), 'updated_at' => now()]);

    DB::table('sqli_order_items')->insert(['order_id' => $orderId, 'notes' => $signature, 'quantity' => 2, 'line_total' => 20]);
    DB::table('sqli_order_items')->insert(['order_id' => $orderId, 'notes' => $signature, 'quantity' => 3, 'line_total' => 30]);
    DB::table('sqli_order_items')->insert(['order_id' => $orderId, 'notes' => 'Coke', 'quantity' => 1, 'line_total' => 5]);

    // Same expression used by get_completed_orders.php for its summary.
    $summary = DB::table('sqli_order_items as order_items')
        ->where('order_id', $orderId)
        ->selectRaw('TRIM(order_items.notes) AS name, SUM(order_items.quantity) AS qty')
        ->groupBy(DB::raw('TRIM(order_items.notes)'))
        ->orderBy('name')
        ->get();

    // '; DROP ...' sorts before 'Coke' (0x27 vs 0x43).
    expect($summary->pluck('name')->all())->toBe([$signature, 'Coke']);
    expect($summary->firstWhere('name', $signature)->qty)->toBe(5);
    expect($summary->firstWhere('name', 'Coke')->qty)->toBe(1);

    // The payload was a literal, not a stacked DROP: the orders table survives.
    expect(Schema::hasTable('sqli_orders'))->toBeTrue();
    expect(DB::table('sqli_orders')->count())->toBe(1);
});

test('LIKE search with embedded payload stays literal and cannot widen results', function () {
    bootSqlInjectionTestApp();

    DB::table('sqli_staff')->insert(['full_name' => 'Alice Good', 'email' => 'alice@example.com', 'role' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sqli_staff')->insert(['full_name' => 'Bob Evil', 'email' => 'bob@example.com', 'role' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);

    $q = "%' OR '1'='1";
    $matches = DB::table('sqli_staff')->where('full_name', 'like', '%' . $q . '%')->pluck('full_name')->all();

    // A tautology must not surface Alice/Bob; the wildcard pattern only
    // matches names that literally contain the payload text.
    expect($matches)->toHaveCount(0);
});

test('strict whole-number ID guard rejects injection-shaped ids', function () {
    bootSqlInjectionTestApp();

    $injected = [
        '1 OR 1=1',
        '1; DROP TABLE sqli_staff; --',
        '1 union select 1',
        '1.5',
        '1e3',
        ' 1',
        '1 ',
        '1abc',
        'abc',
        '',
        null,
        [],
        ['1'],
        true,
    ];
    foreach ($injected as $value) {
        expect(isWholeNumberId($value))->toBeFalse();
    }

    $accepted = [
        0,
        1,
        12345,
        '42',
        '0007',
    ];
    foreach ($accepted as $value) {
        expect(isWholeNumberId($value))->toBeTrue();
    }
});

test('id-based queries treat non-numeric strings as non-matches, never SQL', function () {
    bootSqlInjectionTestApp();

    $id = DB::table('sqli_staff')->insertGetId(['full_name' => 'Tester', 'email' => 'tester@example.com', 'role' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);

    // An attacker string flowing into where('id', ...) matches nothing and
    // does not error (the query builder binds it as a literal).
    $rows = DB::table('sqli_staff')->where('id', "{$id} OR 1=1")->get();
    expect($rows)->toHaveCount(0);

    $rows = DB::table('sqli_staff')->where('id', $id)->get();
    expect($rows)->toHaveCount(1);
});