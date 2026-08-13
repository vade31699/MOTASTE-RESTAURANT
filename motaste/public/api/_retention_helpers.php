<?php

/**
 * Retention/archiving helpers for the staff dashboard.
 *
 * Used by the scheduled tasks in routes/console.php (monthly log + login-history
 * staging, six-month order staging) and by the admin retention API endpoints.
 *
 * Every "batch" is a row in data_retention_batches that pins a window of
 * records — [period_start, period_end) — plus a record count. Staging only
 * happens once per window (deduped by batch_type + period_label); after the
 * admin exports and confirms, clearRetentionBatch() permanently deletes the
 * rows in that window.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function ensureRetentionBatchesTable(): void
{
    if (Schema::hasTable('data_retention_batches')) {
        return;
    }
    try {
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

            $table->index(['batch_type', 'status'], 'retention_batches_type_status_idx');
        });
    } catch (Throwable $error) {
        error_log('ensureRetentionBatchesTable failed: ' . $error->getMessage());
    }
}

/**
 * The single Admin account email used for retention notifications.
 */
function getRetentionAdminEmail(): ?string
{
    try {
        $email = DB::table('staff')->where('role', 'Admin')->value('email');
        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    } catch (Throwable $error) {
        error_log('getRetentionAdminEmail failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Escape a single CSV field (RFC 4180-ish: quote + double embedded quotes).
 */
function retentionCsvEscape($value): string
{
    $value = (string)($value ?? '');
    $value = str_replace(["\r", "\n"], ' ', $value);
    if (strpbrk($value, ",\"\n") !== false || strpos($value, '"') !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * Build a CSV payload (with a UTF-8 BOM so Excel opens it correctly) from a
 * header row and an array of associative rows.
 */
function buildRetentionCsv(array $headers, array $rows): string
{
    $out = "\xEF\xBB\xBF";
    $out .= implode(',', array_map('retentionCsvEscape', $headers)) . "\r\n";
    foreach ($rows as $row) {
        $out .= implode(',', array_map('retentionCsvEscape', $row)) . "\r\n";
    }
    return $out;
}

/**
 * Fetch the raw rows that belong to a retention window for a batch type.
 * Rows are returned as associative arrays with keys matching the export
 * headers for that type.
 */
function fetchRetentionBatchRows(string $batchType, $periodStart, $periodEnd): array
{
    $rows = [];
    try {
        if ($batchType === 'logs') {
            foreach (['order_activity_logs', 'review_activity_logs'] as $tableName) {
                if (!Schema::hasTable($tableName)) {
                    continue;
                }
                $source = $tableName === 'order_activity_logs' ? 'Order' : 'Review';
                $query = DB::table($tableName)
                    ->where('created_at', '>=', $periodStart)
                    ->where('created_at', '<', $periodEnd)
                    ->orderBy('created_at');
                foreach ($query->get() as $row) {
                    $rows[] = [
                        'id' => (int)($row->id ?? 0),
                        'source' => $source,
                        'action' => (string)($row->action ?? ''),
                        'actor_role' => (string)($row->actor_role ?? ''),
                        'actor_email' => (string)($row->actor_email ?? ''),
                        'summary' => (string)($row->summary ?? ''),
                        'details' => (string)($row->details ?? ''),
                        'created_at' => (string)($row->created_at ?? ''),
                    ];
                }
            }
        } elseif ($batchType === 'login_history') {
            if (Schema::hasTable('staff_login_history')) {
                $query = DB::table('staff_login_history')
                    ->where(function ($q) use ($periodStart, $periodEnd) {
                        $q->whereBetween('logged_in_at', [$periodStart, $periodEnd])
                            ->orWhereBetween('created_at', [$periodStart, $periodEnd]);
                    })
                    ->orderByDesc('logged_in_at');
                foreach ($query->get() as $row) {
                    $rows[] = [
                        'id' => (int)($row->id ?? 0),
                        'email' => (string)($row->email ?? ''),
                        'role' => (string)($row->role ?? ''),
                        'full_name' => (string)($row->full_name ?? ''),
                        'device_label' => (string)($row->device_label ?? ''),
                        'ip_address' => (string)($row->ip_address ?? ''),
                        'logged_in_at' => (string)($row->logged_in_at ?? ''),
                    ];
                }
            }
        } elseif ($batchType === 'orders') {
            if (Schema::hasTable('orders')) {
                $orders = DB::table('orders')
                    ->where('order_date', '>=', $periodStart)
                    ->where('order_date', '<', $periodEnd)
                    ->orderBy('order_date')
                    ->get();

                $itemsByOrder = [];
                if ($orders->isNotEmpty() && Schema::hasTable('order_items')) {
                    $itemsByOrder = DB::table('order_items')
                        ->whereIn('order_id', $orders->pluck('id')->all())
                        ->get()
                        ->groupBy('order_id');
                }

                foreach ($orders as $order) {
                    $items = isset($itemsByOrder[$order->id])
                        ? collect($itemsByOrder[$order->id])
                        : collect();
                    $itemSummary = $items
                        ->map(fn ($item) => trim((string)($item->notes ?? 'Menu item')) . ' x' . (int)($item->quantity ?? 0))
                        ->implode('; ');
                    $rows[] = [
                        'id' => (int)($order->id ?? 0),
                        'order_number' => (string)($order->order_number ?? ''),
                        'order_date' => (string)($order->order_date ?? ''),
                        'status' => (string)($order->status ?? ''),
                        'payment_method' => (string)($order->payment_method ?? ''),
                        'order_type' => (string)($order->order_type ?? ''),
                        'customer_name' => (string)($order->customer_name ?? ''),
                        'subtotal' => number_format((float)($order->subtotal ?? 0), 2),
                        'total_amount' => number_format((float)($order->total_amount ?? 0), 2),
                        'items' => $itemSummary,
                    ];
                }
            }
        }
    } catch (Throwable $error) {
        error_log('fetchRetentionBatchRows failed: ' . $error->getMessage());
    }
    return $rows;
}

/**
 * The export column headers for a batch type.
 */
function getRetentionBatchHeaders(string $batchType): array
{
    if ($batchType === 'login_history') {
        return ['ID', 'Email', 'Role', 'Full Name', 'Device', 'IP Address', 'Logged In At'];
    }
    if ($batchType === 'orders') {
        return ['ID', 'Order Number', 'Order Date', 'Status', 'Payment', 'Order Type', 'Customer', 'Subtotal', 'Total', 'Items'];
    }
    return ['ID', 'Source', 'Action', 'Actor Role', 'Actor Email', 'Summary', 'Details', 'Created At'];
}

/**
 * Stage a retention batch if one does not already exist for the same
 * type + period label. Returns the created batch (as an array) or null when
 * the window was already staged or empty.
 *
 * The email (with a CSV attachment) is sent by the caller so it can be
 * skipped for the very first run if desired.
 */
function stageRetentionBatch(string $batchType, string $periodLabel, $periodStart, $periodEnd): ?array
{
    ensureRetentionBatchesTable();

    try {
        $existing = DB::table('data_retention_batches')
            ->where('batch_type', $batchType)
            ->where('period_label', $periodLabel)
            ->first();
        if ($existing) {
            return null; // already staged (pending, exported, or cleared)
        }

        $rows = fetchRetentionBatchRows($batchType, $periodStart, $periodEnd);
        if (count($rows) === 0) {
            return null;
        }

        $now = now();
        $id = DB::table('data_retention_batches')->insertGetId([
            'batch_type' => $batchType,
            'period_label' => $periodLabel,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'record_count' => count($rows),
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'batch_type' => $batchType,
            'period_label' => $periodLabel,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'record_count' => count($rows),
            'status' => 'pending',
        ];
    } catch (Throwable $error) {
        error_log('stageRetentionBatch failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Monthly staging for general system logs (order + review activity logs) and
 * for staff login history. Runs on the 1st of each month and stages the whole
 * previous calendar month.
 */
function stageMonthlyRetentionBatches(): array
{
    require_once __DIR__ . '/_email_auth_helpers.php';

    $results = [];
    $monthStart = now()->startOfMonth();            // first instant of this month (UTC)
    $prevMonthStart = $monthStart->copy()->subMonth(); // first instant of last month

    $logBatch = stageRetentionBatch('logs', $prevMonthStart->format('Y-m'), $prevMonthStart, $monthStart);
    if ($logBatch) {
        $results['logs'] = $logBatch;
        notifyAdminRetentionBatch($logBatch);
    }

    $loginBatch = stageRetentionBatch('login_history', $prevMonthStart->format('Y-m'), $prevMonthStart, $monthStart);
    if ($loginBatch) {
        $results['login_history'] = $loginBatch;
        notifyAdminRetentionBatch($loginBatch);
    }

    return $results;
}

/**
 * Semi-annual staging for completed sales/order history older than 6 months.
 * Uses a rolling window: the start of the window is the previous orders batch's
 * end (or the 6-month cutoff itself on the first run), so no order is ever
 * staged twice.
 */
function stageSixMonthOrderBatches(): array
{
    require_once __DIR__ . '/_email_auth_helpers.php';

    ensureRetentionBatchesTable();

    $cutoff = now()->subMonths(6); // records strictly older than 6 months

    // Rolling start: reuse the end of the most recent orders batch so the same
    // orders are never staged again after a clear.
    $lastEnd = DB::table('data_retention_batches')
        ->where('batch_type', 'orders')
        ->orderByDesc('period_end')
        ->value('period_end');
    $periodStart = $lastEnd ?: $cutoff->copy()->subMonths(6);

    $batch = stageRetentionBatch('orders', $cutoff->format('Y-m'), $periodStart, $cutoff);
    if (!$batch) {
        return [];
    }

    notifyAdminRetentionBatch($batch);
    return ['orders' => $batch];
}

/**
 * Send the admin the retention notification email with the CSV attachment.
 */
function notifyAdminRetentionBatch(array $batch): void
{
    try {
        $adminEmail = getRetentionAdminEmail();
        if (!$adminEmail) {
            return;
        }

        $labels = [
            'logs' => 'System Logs',
            'login_history' => 'Staff Login History',
            'orders' => 'Sales & Order History (6-month retention)',
        ];
        $typeLabel = $labels[$batch['batch_type']] ?? $batch['batch_type'];
        $start = (string)($batch['period_start'] ?? '');
        $end = (string)($batch['period_end'] ?? '');

        $rows = fetchRetentionBatchRows($batch['batch_type'], $batch['period_start'], $batch['period_end']);
        $csv = buildRetentionCsv(getRetentionBatchHeaders($batch['batch_type']), $rows);

        $subject = sprintf('MOTASTE Retention: %s ready to archive (%s)', $typeLabel, $batch['period_label']);
        $body = "MOTASTE Data Retention Notice\n\n"
            . "A new archive batch is ready for {$typeLabel}.\n\n"
            . "Period: {$start} to {$end}\n"
            . "Records: {$batch['record_count']}\n\n"
            . "The exported data is attached as a CSV (opens in Excel).\n"
            . "Log in to the staff dashboard to download it as Excel or clear "
            . "these records from the database.\n";

        $attachment = [
            'name' => sprintf('MOTASTE-%s-%s.csv', $batch['batch_type'], $batch['period_label']),
            'content' => $csv,
            'mime' => 'text/csv',
        ];

        $result = sendSystemEmail($adminEmail, $subject, $body, $attachment);
        if (!$result['success']) {
            error_log('retention notify email failed: ' . ($result['error'] ?? 'unknown'));
        }

        // Mark as notified regardless so the dashboard banner leads the flow.
        DB::table('data_retention_batches')
            ->where('id', $batch['id'])
            ->update(['notified_at' => now(), 'updated_at' => now()]);
    } catch (Throwable $error) {
        error_log('notifyAdminRetentionBatch failed: ' . $error->getMessage());
    }
}

/**
 * List batches for the admin dashboard (most recent first).
 */
function getRetentionBatchesForAdmin(): array
{
    ensureRetentionBatchesTable();
    try {
        return DB::table('data_retention_batches')
            ->orderByDesc('period_end')
            ->limit(50)
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => (int)($batch->id ?? 0),
                    'batch_type' => (string)($batch->batch_type ?? ''),
                    'period_label' => (string)($batch->period_label ?? ''),
                    'period_start' => $batch->period_start ?? null,
                    'period_end' => $batch->period_end ?? null,
                    'record_count' => (int)($batch->record_count ?? 0),
                    'status' => (string)($batch->status ?? 'pending'),
                    'notified_at' => $batch->notified_at ?? null,
                    'exported_at' => $batch->exported_at ?? null,
                    'cleared_at' => $batch->cleared_at ?? null,
                    'created_at' => $batch->created_at ?? null,
                ];
            })
            ->values()
            ->all();
    } catch (Throwable $error) {
        error_log('getRetentionBatchesForAdmin failed: ' . $error->getMessage());
        return [];
    }
}

/**
 * Permanently delete the records covered by a batch window and mark the batch
 * cleared. Returns ['deleted' => n] or throws on failure.
 */
function clearRetentionBatch(int $batchId): array
{
    ensureRetentionBatchesTable();

    $batch = DB::table('data_retention_batches')->where('id', $batchId)->first();
    if (!$batch) {
        throw new RuntimeException('Retention batch not found');
    }
    if ((string)($batch->status ?? '') === 'cleared') {
        return ['deleted' => 0];
    }

    $type = (string)$batch->batch_type;
    $start = $batch->period_start ?? (string)$batch->period_start;
    $end = $batch->period_end;

    $deleted = 0;
    DB::transaction(function () use ($type, $start, $end, $batchId, &$deleted) {
        if ($type === 'logs') {
            foreach (['order_activity_logs', 'review_activity_logs'] as $tableName) {
                if (!Schema::hasTable($tableName)) {
                    continue;
                }
                $deleted += DB::table($tableName)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->delete();
            }
        } elseif ($type === 'login_history') {
            if (Schema::hasTable('staff_login_history')) {
                $deleted += DB::table('staff_login_history')
                    ->where(function ($q) use ($start, $end) {
                        $q->whereBetween('logged_in_at', [$start, $end])
                            ->orWhereBetween('created_at', [$start, $end]);
                    })
                    ->delete();
            }
        } elseif ($type === 'orders') {
            if (Schema::hasTable('orders')) {
                $orderIds = DB::table('orders')
                    ->where('order_date', '>=', $start)
                    ->where('order_date', '<', $end)
                    ->pluck('id')
                    ->all();

                if (count($orderIds) > 0) {
                    if (Schema::hasTable('order_items')) {
                        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
                    }
                    $deleted += DB::table('orders')->whereIn('id', $orderIds)->delete();
                }
            }
        }

        DB::table('data_retention_batches')->where('id', $batchId)->update([
            'status' => 'cleared',
            'cleared_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['deleted' => $deleted];
}
