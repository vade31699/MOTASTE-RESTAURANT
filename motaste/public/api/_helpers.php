<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Shared helpers for public API endpoints.
 */

/**
 * Ensure the order preparation timer columns exist. Schema is normally managed
 * by Laravel migrations; the inline fallback keeps order endpoints working even
 * when migrations have not been run on the deployment yet.
 */
function ensureOrderPrepTimerColumns(): void
{
    try {
        if (!Schema::hasColumn('orders', 'prep_minutes')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('prep_minutes')->nullable();
            });
        }
        if (!Schema::hasColumn('orders', 'prep_started_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('prep_started_at')->nullable();
            });
        }
    } catch (Throwable $error) {
        // Schema changes must never block order processing; the migration will
        // apply the columns on deploy.
        error_log('orders prep timer columns check failed: ' . $error->getMessage());
    }
}

/**
 * Ensure the staff login history table exists for the credentials audit trail.
 */
function ensureStaffLoginHistoryTable(): void
{
    try {
        if (!Schema::hasTable('staff_login_history')) {
            Schema::create('staff_login_history', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('role', 100)->nullable();
                $table->string('full_name', 191)->nullable();
                $table->string('device_label', 191)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('logged_in_at')->nullable();
                $table->timestamps();

                $table->index('email', 'staff_login_history_email_idx');
                $table->index('role', 'staff_login_history_role_idx');
                $table->index('logged_in_at', 'staff_login_history_logged_in_at_idx');
            });
        }
    } catch (Throwable $error) {
        // Auditing must never block the login response.
        error_log('staff_login_history table check failed: ' . $error->getMessage());
    }
}

/**
 * Record a successful staff login into the login history audit table.
 */
function recordStaffLoginHistory(string $email, string $role, ?string $fullName = null): void
{
    ensureStaffLoginHistoryTable();

    try {
        DB::table('staff_login_history')->insert([
            'email' => strtolower(trim($email)),
            'role' => trim($role) !== '' ? trim($role) : null,
            'full_name' => trim((string)$fullName) !== '' ? trim((string)$fullName) : null,
            'device_label' => function_exists('resolveDeviceLabel') ? resolveDeviceLabel() : null,
            'user_agent' => trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'ip_address' => function_exists('resolveClientIpAddress') ? resolveClientIpAddress() : null,
            'logged_in_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    } catch (Throwable $error) {
        error_log('staff login history insert failed: ' . $error->getMessage());
    }
}

function normalizeInventoryName(?string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return mb_strtolower($value);
}

function normalizeItemName(?string $value): string
{
    return normalizeInventoryName($value);
}

/**
 * Build a short human-readable summary from an iterable of order item rows.
 * Accepts arrays or objects with `notes`/`quantity` fields.
 */
function buildOrderSummary($orderItems): string
{
    if (!is_iterable($orderItems)) {
        return '';
    }

    $parts = [];
    foreach ($orderItems as $it) {
        $name = '';
        $qty = 0;
        if (is_object($it)) {
            $name = (string)($it->notes ?? '');
            $qty = (int)($it->quantity ?? 0);
        } elseif (is_array($it)) {
            $name = (string)($it['notes'] ?? '');
            $qty = (int)($it['quantity'] ?? 0);
        }

        $name = trim($name);
        if ($name === '') continue;
        $parts[] = $name . ' x' . $qty;
    }

    return implode(', ', $parts);
}
