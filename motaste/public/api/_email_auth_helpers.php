<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

function ensureStaffAccountSnapshotsTable(): void
{
    DB::statement("CREATE TABLE IF NOT EXISTS staff_account_snapshots (
        id BIGSERIAL PRIMARY KEY,
        snapshot_key VARCHAR(191) NOT NULL UNIQUE,
        snapshot_payload TEXT NOT NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL
    )");
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

        if ($name === '' || $role === '' || $email === '' || $password === '') {
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

    if ($adminIndex < 0) {
        array_unshift($normalized, [
            'name' => 'Administrator',
            'role' => 'Admin',
            'email' => 'vadevidad31699@gmail.com',
            'password' => 'admin123',
            'inviteConfirmed' => true,
        ]);
    } else {
        $normalized[$adminIndex]['inviteConfirmed'] = true;
        if ($normalized[$adminIndex]['email'] === 'admin@motaste.com' || $normalized[$adminIndex]['email'] === '') {
            $normalized[$adminIndex]['email'] = 'vadevidad31699@gmail.com';
        }
        if ($normalized[$adminIndex]['password'] === '') {
            $normalized[$adminIndex]['password'] = 'admin123';
        }
    }

    return $normalized;
}

function loadStaffAccountsSnapshot(): array
{
    ensureStaffAccountSnapshotsTable();

    $snapshot = DB::table('staff_account_snapshots')
        ->where('snapshot_key', 'motaste-staff-accounts')
        ->value('snapshot_payload');

    $decoded = $snapshot ? json_decode((string)$snapshot, true) : [];
    return normalizeStaffAccountsSnapshot($decoded);
}

function saveStaffAccountsSnapshot(array $accounts): void
{
    ensureStaffAccountSnapshotsTable();
    $normalized = normalizeStaffAccountsSnapshot($accounts);

    $now = now();
    DB::table('staff_account_snapshots')->updateOrInsert(
        ['snapshot_key' => 'motaste-staff-accounts'],
        [
            'snapshot_payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
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
