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
    DB::statement("CREATE TABLE IF NOT EXISTS staff_invite_tokens (
        id BIGSERIAL PRIMARY KEY,
        email VARCHAR(191) NOT NULL,
        role VARCHAR(100) NOT NULL,
        code_hash VARCHAR(191) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
}

function ensureAdminCredentialChangeTokensTable(): void
{
    DB::statement("CREATE TABLE IF NOT EXISTS admin_credential_change_tokens (
        id BIGSERIAL PRIMARY KEY,
        current_email VARCHAR(191) NOT NULL,
        code_hash VARCHAR(191) NOT NULL,
        pending_email VARCHAR(191) NOT NULL,
        pending_password VARCHAR(191) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
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
        $rows = DB::table('staff')
            ->select('full_name', 'role', 'email', 'password_hash', 'created_at')
            ->orderBy('id', 'asc')
            ->get()
            ->all();

        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = [
                'name' => trim((string)($row->full_name ?? '')) ?: 'Staff',
                'role' => trim((string)($row->role ?? '')) ?: 'Staff',
                'email' => strtolower(trim((string)($row->email ?? ''))),
                // Do not expose password hashes as plaintext to clients.
                'password' => '',
                'inviteConfirmed' => true,
            ];
        }

        // Ensure admin fallback exists and is normalized
        $normalized = normalizeStaffAccountsSnapshot($accounts);
        return $normalized;
    } catch (Throwable $error) {
        // Fallback to previous snapshot behavior when DB access fails
        ensureStaffAccountSnapshotsTable();
        $snapshot = DB::table('staff_account_snapshots')
            ->where('snapshot_key', 'motaste-staff-accounts')
            ->value('snapshot_payload');

        $decoded = $snapshot ? json_decode((string)$snapshot, true) : [];
        return normalizeStaffAccountsSnapshot($decoded);
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

        // Upsert into staff table using email as key. If password not provided,
        // do not overwrite existing password_hash.
        $existing = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            $update = [
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
                'full_name' => $name,
                'role' => $role,
                'email' => $email,
                'password_hash' => $passwordHash !== '' ? $passwordHash : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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

function sendSystemEmail(string $to, string $subject, string $body): array
{
    $to = trim($to);
    if ($to === '') {
        return ['success' => false, 'error' => 'Recipient email is required'];
    }

    $smtpHost = trim((string)config('mail.mailers.smtp.host', ''));
    $smtpPort = (string)config('mail.mailers.smtp.port', '');
    $smtpUser = trim((string)config('mail.mailers.smtp.username', ''));
    $smtpPass = (string)config('mail.mailers.smtp.password', '');
    $smtpScheme = trim((string)config('mail.mailers.smtp.scheme', ''));

    // Cloud envs can lag behind config cache; pull direct env values when config is empty.
    if ($smtpHost === '') {
        $smtpHost = trim((string)(env('MAIL_HOST') ?: (getenv('MAIL_HOST') ?: '')));
    }
    if ($smtpPort === '') {
        $smtpPort = (string)(env('MAIL_PORT') ?: (getenv('MAIL_PORT') ?: ''));
    }
    if ($smtpUser === '') {
        $smtpUser = trim((string)(env('MAIL_USERNAME') ?: (getenv('MAIL_USERNAME') ?: '')));
    }
    if ($smtpPass === '') {
        $smtpPass = (string)(env('MAIL_PASSWORD') ?: (getenv('MAIL_PASSWORD') ?: ''));
    }
    if ($smtpScheme === '') {
        $smtpScheme = trim((string)(env('MAIL_SCHEME') ?: (getenv('MAIL_SCHEME') ?: '')));
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
        return [
            'success' => false,
            'driver' => 'smtp',
            'delivered' => false,
            'error' => 'SMTP configuration is incomplete in deployment environment. Missing: ' . implode(', ', $missing),
        ];
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
