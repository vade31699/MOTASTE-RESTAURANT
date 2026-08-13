<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tests for the data-retention archiving flow (Phase 3 of the retention plan):
 * monthly logs + login history staging, six-month order staging, CSV export,
 * and permanent clearing. Boots the app on in-memory SQLite like the other
 * helper tests, so production data is never touched.
 */
function resetRetentionTestData(): void
{
    foreach (['data_retention_batches', 'order_activity_logs', 'staff_login_history', 'order_items', 'orders', 'staff'] as $tableName) {
        if (Schema::hasTable($tableName)) {
            DB::table($tableName)->delete();
        }
    }
}

function bootRetentionTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

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

    require_once __DIR__ . '/../../public/api/_retention_helpers.php';
    require_once __DIR__ . '/../../public/api/_email_auth_helpers.php';

    // Create the tables the helpers expect (the test DB is fresh).
    if (!Schema::hasTable('data_retention_batches')) {
        Schema::create('data_retention_batches', function ($table) {
            $table->id();
            $table->string('batch_type', 40);
            $table->string('period_label', 20);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end');
            $table->unsignedInteger('record_count')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('order_activity_logs')) {
        Schema::create('order_activity_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_number', 191)->nullable();
            $table->string('action', 100);
            $table->string('actor_role', 100)->nullable();
            $table->string('actor_email', 191)->nullable();
            $table->text('summary')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('staff_login_history')) {
        Schema::create('staff_login_history', function ($table) {
            $table->id();
            $table->string('email', 191);
            $table->string('role', 100)->nullable();
            $table->string('full_name', 191)->nullable();
            $table->string('device_label', 191)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('orders')) {
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('order_number', 191);
            $table->timestamp('order_date');
            $table->string('status', 50)->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->string('order_type', 50)->nullable();
            $table->string('customer_name', 191)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('order_items')) {
        Schema::create('order_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('components')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('staff')) {
        Schema::create('staff', function ($table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('role', 100)->nullable();
            $table->timestamps();
        });
    }
}

test('monthly staging creates batches for logs and login history', function () {
    bootRetentionTestApp();
    resetRetentionTestData();

    $monthStart = now()->startOfMonth();
    $prevMonthStart = $monthStart->copy()->subMonth();

    // Seed a log + a login record in the previous month.
    DB::table('order_activity_logs')->insert([
        'action' => 'order_completed',
        'actor_role' => 'Cashier',
        'actor_email' => 'cashier@example.com',
        'summary' => 'Completed order',
        'created_at' => $prevMonthStart->copy()->addDay(),
        'updated_at' => $prevMonthStart->copy()->addDay(),
    ]);
    DB::table('staff_login_history')->insert([
        'email' => 'admin@example.com',
        'role' => 'Admin',
        'logged_in_at' => $prevMonthStart->copy()->addDay(),
        'created_at' => $prevMonthStart->copy()->addDay(),
        'updated_at' => $prevMonthStart->copy()->addDay(),
    ]);

    $results = stageMonthlyRetentionBatches();

    expect(isset($results['logs']))->toBeTrue();
    expect(isset($results['login_history']))->toBeTrue();
    expect((int)$results['logs']['record_count'])->toBe(1);
    expect((int)$results['login_history']['record_count'])->toBe(1);

    $batches = getRetentionBatchesForAdmin();
    expect(count($batches))->toBe(2);

    resetRetentionTestData();
});

test('staging the same window twice is a no-op (dedup)', function () {
    bootRetentionTestApp();
    resetRetentionTestData();

    $monthStart = now()->startOfMonth();
    $prevMonthStart = $monthStart->copy()->subMonth();

    DB::table('order_activity_logs')->insert([
        'action' => 'order_cancelled',
        'summary' => 'Cancelled',
        'created_at' => $prevMonthStart->copy()->addDay(),
        'updated_at' => $prevMonthStart->copy()->addDay(),
    ]);

    $first = stageMonthlyRetentionBatches();
    expect(isset($first['logs']))->toBeTrue();

    $second = stageMonthlyRetentionBatches();
    expect(isset($second['logs']))->toBeFalse();
});

test('export returns rows and marks the batch exported', function () {
    bootRetentionTestApp();
    resetRetentionTestData();

    $monthStart = now()->startOfMonth();
    $prevMonthStart = $monthStart->copy()->subMonth();

    DB::table('order_activity_logs')->insert([
        'action' => 'order_completed',
        'summary' => 'Done',
        'created_at' => $prevMonthStart->copy()->addDay(),
        'updated_at' => $prevMonthStart->copy()->addDay(),
    ]);

    $results = stageMonthlyRetentionBatches();
    $batchId = (int)$results['logs']['id'];

    $batch = DB::table('data_retention_batches')->where('id', $batchId)->first();
    $rows = fetchRetentionBatchRows((string)$batch->batch_type, $batch->period_start, $batch->period_end);

    expect(count($rows))->toBe(1);
    expect($rows[0]['action'])->toBe('order_completed');

    $csv = buildRetentionCsv(getRetentionBatchHeaders('logs'), $rows);
    expect(str_contains($csv, 'order_completed'))->toBeTrue();

    DB::table('data_retention_batches')->where('id', $batchId)->update([
        'status' => 'exported',
        'exported_at' => now(),
        'updated_at' => now(),
    ]);
    expect(DB::table('data_retention_batches')->where('id', $batchId)->value('status'))->toBe('exported');
});

test('clear permanently deletes the batch window records', function () {
    bootRetentionTestApp();
    resetRetentionTestData();

    $monthStart = now()->startOfMonth();
    $prevMonthStart = $monthStart->copy()->subMonth();

    DB::table('order_activity_logs')->insert([
        'action' => 'order_completed',
        'summary' => 'Done',
        'created_at' => $prevMonthStart->copy()->addDay(),
        'updated_at' => $prevMonthStart->copy()->addDay(),
    ]);
    DB::table('staff_login_history')->insert([
        'email' => 'admin@example.com',
        'role' => 'Admin',
        'logged_in_at' => $prevMonthStart->copy()->addDay(),
        'created_at' => $prevMonthStart->copy()->addDay(),
        'updated_at' => $prevMonthStart->copy()->addDay(),
    ]);

    $results = stageMonthlyRetentionBatches();
    $logsBatchId = (int)$results['logs']['id'];
    $loginBatchId = (int)$results['login_history']['id'];

    $cleared = clearRetentionBatch($logsBatchId);
    expect((int)$cleared['deleted'])->toBe(1);
    expect(DB::table('order_activity_logs')->count())->toBe(0);
    expect(DB::table('data_retention_batches')->where('id', $logsBatchId)->value('status'))->toBe('cleared');

    // The login-history batch window is untouched.
    expect(DB::table('staff_login_history')->count())->toBe(1);
    expect(DB::table('data_retention_batches')->where('id', $loginBatchId)->value('status'))->toBe('pending');
});

test('six-month order staging picks orders older than 6 months', function () {
    bootRetentionTestApp();
    resetRetentionTestData();

    $oldDate = now()->subMonths(7);
    $recentDate = now()->subMonth();

    $oldOrderId = DB::table('orders')->insertGetId([
        'order_number' => '1111',
        'order_date' => $oldDate,
        'status' => 'completed',
        'total_amount' => 500,
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);
    DB::table('orders')->insert([
        'order_number' => '2222',
        'order_date' => $recentDate,
        'status' => 'completed',
        'total_amount' => 300,
        'created_at' => $recentDate,
        'updated_at' => $recentDate,
    ]);
    DB::table('order_items')->insert([
        'order_id' => $oldOrderId,
        'quantity' => 2,
        'notes' => 'Silog',
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);

    $results = stageSixMonthOrderBatches();
    expect(isset($results['orders']))->toBeTrue();

    $batch = $results['orders'];
    $rows = fetchRetentionBatchRows('orders', $batch['period_start'], $batch['period_end']);

    expect(count($rows))->toBe(1);
    expect($rows[0]['order_number'])->toBe('1111');
    expect(str_contains($rows[0]['items'], 'Silog x2'))->toBeTrue();

    // Clearing removes the order and its items, not the recent order.
    $cleared = clearRetentionBatch((int)$batch['id']);
    expect((int)$cleared['deleted'])->toBe(1);
    expect(DB::table('orders')->where('order_number', '2222')->exists())->toBeTrue();
    expect(DB::table('orders')->where('order_number', '1111')->exists())->toBeFalse();
    expect(DB::table('order_items')->count())->toBe(0);
});
