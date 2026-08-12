<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

function ensureStaffAccountSnapshotsTable(): void
{
    // Deprecated: previously used a snapshots table. Keep as no-op for compatibility.
    return;
}

function ensureStaffInviteTokensTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

function ensureAdminCredentialChangeTokensTable(): void
{
    // Schema is managed by Laravel migrations.
    return;
}

function normalizeStaffAccountsSnapshot($snapshot): array
{
    if (!is_array($snapshot)) {
        $snapshot = [];
    }

    $normalized = [];
    foreach ($snapshot as $account) {
        if (!is_array($account)) {
            continue;
        }

        $name = trim((string)($account['name'] ?? ''));
        $role = trim((string)($account['role'] ?? ''));
        $email = strtolower(trim((string)($account['email'] ?? '')));
        $password = (string)($account['password'] ?? '');
        $inviteConfirmed = $role === 'Admin' ? true : (bool)($account['inviteConfirmed'] ?? false);

            if ($name === '' || $role === '' || $email === '') {
                continue;
            }

        if (!in_array($role, ['Admin', 'Cashier', 'Inventory Manager'], true)) {
            continue;
        }

        $normalized[] = [
            'name' => $name,
            'role' => $role,
            'email' => $email,
            'password' => $password,
            'inviteConfirmed' => $inviteConfirmed,
        ];
    }

    $adminIndex = -1;
    foreach ($normalized as $index => $account) {
        if ($account['role'] === 'Admin') {
            $adminIndex = $index;
            break;
        }
    }

    // Do not inject or assume any default admin credentials here.
    if ($adminIndex >= 0) {
        $normalized[$adminIndex]['inviteConfirmed'] = true;
    }

    return $normalized;
}

function loadStaffAccountsSnapshot(): array
{
    // Read accounts from the application's staff table instead of snapshot storage.
    // This returns a normalized array suitable for the public APIs. Passwords
    // cannot be recovered from hashes, so returned accounts will omit plaintext
    // passwords (client-side may still have persisted credentials in localStorage).
    try {
        ensureStaffInviteTokensTable();

        $rows = DB::table('staff')
            ->select('full_name', 'role', 'email', 'password_hash', 'created_at')
            ->orderBy('id', 'asc')
            ->get()
            ->all();

        $accounts = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string)($row->email ?? '')));
            $role = trim((string)($row->role ?? '')) ?: 'Staff';
            $inviteConfirmed = true;

            if (in_array($role, ['Cashier', 'Inventory Manager'], true)) {
                $token = DB::table('staff_invite_tokens')
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->whereRaw('LOWER(role) = ?', [strtolower($role)])
                    ->first();

                $inviteConfirmed = $token ? false : true;
            }

            $accounts[] = [
                'name' => trim((string)($row->full_name ?? '')) ?: 'Staff',
                'role' => $role,
                'email' => $email,
                // Do not expose password hashes as plaintext to clients.
                'password' => '',
                'inviteConfirmed' => $inviteConfirmed,
            ];
        }

        // Ensure admin fallback exists and is normalized
        $normalized = normalizeStaffAccountsSnapshot($accounts);
        return $normalized;
    } catch (Throwable $error) {
        // Fallback to normalized empty account list when DB access fails.
        return normalizeStaffAccountsSnapshot([]);
    }
}

function saveStaffAccountsSnapshot(array $accounts): void
{
    // Persist accounts into the application's `staff` table to centralize
    // credential storage. Incoming $accounts may contain plaintext passwords
    // (e.g. during an admin credentials change); when present we will hash
    // them before saving. We match rows by email.
    $normalized = normalizeStaffAccountsSnapshot($accounts);

    foreach ($normalized as $account) {
        $email = strtolower(trim((string)($account['email'] ?? '')));
        if ($email === '') continue;

        $name = trim((string)($account['name'] ?? '')) ?: 'Staff';
        $role = trim((string)($account['role'] ?? '')) ?: 'Staff';
        $inviteConfirmed = ($account['inviteConfirmed'] ?? false) ? 1 : 0;

        // Determine if a plaintext password was provided; if so hash it.
        $passwordPlain = isset($account['password']) ? (string)$account['password'] : '';
        $passwordHash = '';
        if ($passwordPlain !== '') {
            $passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);
        }

        // Ensure there is a matching users row for this staff account.
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => $name,
                'updated_at' => now(),
            ]);
            $userId = $user->id;
        } else {
            $userId = DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'password' => $passwordHash !== '' ? $passwordHash : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'email_verified_at' => now(),
                'remember_token' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Upsert into staff table using email as key. If password not provided,
        // do not overwrite existing password_hash.
        $existing = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            $update = [
                'user_id' => $userId,
                'full_name' => $name,
                'role' => $role,
                'updated_at' => now(),
            ];
            if ($passwordHash !== '') {
                $update['password_hash'] = $passwordHash;
            }
            DB::table('staff')->where('id', $existing->id)->update($update);
        } else {
            DB::table('staff')->insert([
                'user_id' => $userId,
                'position' => null,
                'full_name' => $name,
                'role' => $role,
                'email' => $email,
                'password_hash' => $passwordHash !== '' ? $passwordHash : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // Remove any staff records that were deleted from the account list.
    $normalizedEmails = array_map(function ($account) {
        return strtolower(trim((string)$account['email']));
    }, $normalized);

    if (count($normalizedEmails) === 0) {
        DB::table('staff')->where('role', '!=', 'Admin')->delete();
    } else {
        DB::table('staff')
            ->whereNotIn(DB::raw('LOWER(email)'), $normalizedEmails)
            ->where('role', '!=', 'Admin')
            ->delete();
    }

    // Remove any pending invite tokens for accounts that no longer exist.
    ensureStaffInviteTokensTable();
    if (count($normalizedEmails) === 0) {
        DB::table('staff_invite_tokens')->delete();
    } else {
        DB::table('staff_invite_tokens')
            ->whereNotIn(DB::raw('LOWER(email)'), $normalizedEmails)
            ->delete();
    }
}

function getAdminAccount(array $accounts): ?array
{
    foreach ($accounts as $account) {
        if (($account['role'] ?? '') === 'Admin') {
            return $account;
        }
    }

    return null;
}

function generateVerificationCode(int $length = 6): string
{
    $digits = '';
    for ($i = 0; $i < $length; $i += 1) {
        $digits .= (string)random_int(0, 9);
    }

    return $digits;
}

/**
 * Normalize a mail env value: trim it and treat the literal string "null" as
 * an empty value.
 */
function normalizeMailEnv($value): string
{
    $trimmed = trim((string)$value);
    return strtolower($trimmed) === 'null' ? '' : $trimmed;
}

function sendSystemEmail(string $to, string $subject, string $body): array
{
    $to = trim($to);
    if ($to === '') {
        return ['success' => false, 'error' => 'Recipient email is required'];
    }

    // Treat empty and the literal string "null" (common in .env files) as unset.
    $smtpHost = normalizeMailEnv(config('mail.mailers.smtp.host', ''));
    $smtpPort = normalizeMailEnv(config('mail.mailers.smtp.port', ''));
    $smtpUser = normalizeMailEnv(config('mail.mailers.smtp.username', ''));
    $smtpPass = normalizeMailEnv(config('mail.mailers.smtp.password', ''));
    $smtpScheme = normalizeMailEnv(config('mail.mailers.smtp.scheme', ''));

    // Cloud envs can lag behind config cache; pull direct env values when config is empty.
    if ($smtpHost === '') {
        $smtpHost = normalizeMailEnv(env('MAIL_HOST') ?: (getenv('MAIL_HOST') ?: ''));
    }
    if ($smtpPort === '') {
        $smtpPort = normalizeMailEnv(env('MAIL_PORT') ?: (getenv('MAIL_PORT') ?: ''));
    }
    if ($smtpUser === '') {
        $smtpUser = normalizeMailEnv(env('MAIL_USERNAME') ?: (getenv('MAIL_USERNAME') ?: ''));
    }
    if ($smtpPass === '') {
        $smtpPass = normalizeMailEnv(env('MAIL_PASSWORD') ?: (getenv('MAIL_PASSWORD') ?: ''));
    }
    if ($smtpScheme === '') {
        $smtpScheme = normalizeMailEnv(env('MAIL_SCHEME') ?: (getenv('MAIL_SCHEME') ?: ''));
    }

    config([
        'mail.mailers.smtp.host' => $smtpHost,
        'mail.mailers.smtp.port' => $smtpPort,
        'mail.mailers.smtp.username' => $smtpUser,
        'mail.mailers.smtp.password' => $smtpPass,
    ]);
    if ($smtpScheme !== '') {
        config(['mail.mailers.smtp.scheme' => $smtpScheme]);
    }

    $missing = [];
    if ($smtpHost === '') $missing[] = 'MAIL_HOST';
    if ($smtpPort === '') $missing[] = 'MAIL_PORT';
    if ($smtpUser === '') $missing[] = 'MAIL_USERNAME';
    if ($smtpPass === '') $missing[] = 'MAIL_PASSWORD';

    if ($missing) {
        // No SMTP credentials configured (typical for local development). Fall
        // back to the configured default mailer (log/array) so the flow still
        // completes, and write the message to the server log so verification
        // codes remain retrievable.
        $driver = (string)config('mail.default', 'log');
        try {
            Mail::raw($body, function ($message) use ($to, $subject): void {
                $message->to($to)->subject($subject);
            });

            error_log('[MOTASTE mail] (fallback driver: ' . $driver . ') to=' . $to . ' subject=' . $subject . ' body=' . str_replace(["\r", "\n"], ' ', $body));

            return [
                'success' => true,
                'driver' => $driver,
                'delivered' => false,
                'warning' => 'SMTP is not configured; the message was written to the server log instead of being emailed. Missing: ' . implode(', ', $missing),
            ];
        } catch (Throwable $mailError) {
            Log::error('Fallback email send failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $mailError->getMessage(),
            ]);

            return [
                'success' => false,
                'driver' => $driver,
                'delivered' => false,
                'error' => 'Email could not be delivered: ' . $mailError->getMessage(),
            ];
        }
    }

    // Laravel expects smtp/smtps schemes. For Gmail on port 587, smtp enables STARTTLS.
    if ($smtpScheme === '' && stripos($smtpHost, 'gmail.com') !== false && $smtpPort === '587') {
        config(['mail.mailers.smtp.scheme' => 'smtp']);
        $smtpScheme = 'smtp';
    }

    try {
        // Always send through SMTP to avoid log/array default transports.
        Mail::mailer('smtp')->raw($body, function ($message) use ($to, $subject): void {
            $message->to($to)->subject($subject);
        });

        return ['success' => true, 'driver' => 'smtp', 'delivered' => true];
    } catch (Throwable $mailError) {
        Log::error('SMTP email send failed', [
            'to' => $to,
            'subject' => $subject,
            'host' => $smtpHost,
            'port' => $smtpPort,
            'scheme' => $smtpScheme,
            'username' => $smtpUser,
            'error' => $mailError->getMessage(),
        ]);

        return [
            'success' => false,
            'driver' => 'smtp',
            'delivered' => false,
            'error' => 'SMTP send failed: ' . $mailError->getMessage(),
        ];
    }
}
