<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Shared helpers for public API endpoints.
 */

/**
 * Mask an email address for dashboard display (DPA data minimization):
 * "juan.delacruz@gmail.com" -> "j***@gmail.com". Keeps the domain so staff can
 * still distinguish providers, but hides the local part. Non-emails are
 * returned unchanged.
 */
function maskEmailAddressForDisplay(string $email): string
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '') {
        return $email;
    }

    return substr($local, 0, 1) . '***@' . $domain;
}

/**
 * Mask an IPv4/IPv6 address for dashboard display:
 * "112.198.77.9" -> "112.198.*.*", "2001:db8:85a3::8a2e:370:7334" ->
 * "2001:db8:*". Retains enough prefix for staff to correlate repeated
 * abuse from the same network without exposing the full address.
 */
function maskIpAddressForDisplay(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return $ip;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.*.*';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $groups = explode(':', $ip);
        $kept = [];
        foreach ($groups as $group) {
            if (count($kept) >= 2) {
                break;
            }
            $kept[] = $group !== '' ? $group : '0';
        }
        return implode(':', $kept) . ':*';
    }

    return $ip;
}

/**
 * True when a value contains HTML/script injection patterns that must be
 * rejected rather than stored (stored-XSS guard): HTML tags (including
 * malformed ones like "<script>alert<script>"), executable URL schemes, and
 * inline event-handler attributes. Recurses into arrays/objects so whole
 * payloads (e.g. menu snapshots) can be checked in one call. Legitimate
 * image data URIs (data:image/*) are allowed.
 */
function inputContainsUnsafeHtml($value): bool
{
    if (is_array($value) || is_object($value)) {
        foreach ($value as $item) {
            if (inputContainsUnsafeHtml($item)) {
                return true;
            }
        }
        return false;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return false;
    }

    $text = (string)$value;

    // The canonical stored-XSS vector: <script>… (including malformed variants
    // like "<script>alert<script>").
    if (stripos($text, '<script') !== false) {
        return true;
    }

    // Any HTML tag — <img>, <iframe>, <svg>, <a href=…>, closing tags, etc.
    // Requires a tag-like shape so plain text like "a < b" is not rejected.
    if (preg_match('/<\/?[a-z][^>]*>/i', $text) === 1) {
        return true;
    }

    // Executable URL schemes. data: is only dangerous with a non-image payload
    // (data:image/* is how uploaded images are stored); the mime must start
    // with a letter so text like "data: 12/3" is not a false positive.
    if (preg_match('/(?:javascript|vbscript)\s*:/i', $text) === 1) {
        return true;
    }
    if (preg_match('/data\s*:\s*(?!image\/)[a-z][a-z0-9.+-]*\//i', $text) === 1) {
        return true;
    }

    // Inline event handlers, e.g. onerror=, onclick=, onload=.
    if (preg_match('/(?:^|\s)on[a-z]+\s*=/i', $text) === 1) {
        return true;
    }

    return false;
}

/**
 * Reject the request with a 422 when any of the given values (strings or
 * nested arrays/objects) contains HTML/script content, prompting the client
 * to resubmit with plain text. Exits after emitting the JSON error.
 */
function rejectUnsafeInputOrExit(...$values): void
{
    foreach ($values as $value) {
        if (inputContainsUnsafeHtml($value)) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'Input contains HTML or script content that is not allowed. Please use plain text only.',
            ]);
            exit;
        }
    }
}

/**
 * Ensure the order preparation timer columns exist. Schema is normally managed
 * by Laravel migrations; the inline fallback keeps order endpoints working even
 * when migrations have not been run on the deployment yet.
 */
function ensureOrderPrepTimerColumns(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

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
        $verified = true;
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
    static $verified = false;
    if ($verified) {
        return;
    }

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
        $verified = true;
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
