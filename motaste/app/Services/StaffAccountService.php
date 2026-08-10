<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Staff account snapshot helpers (normalize / load / save).
 * Ported from the legacy public/api/_email_auth_helpers.php.
 */
class StaffAccountService
{
    public static function normalizeAccounts(array $snapshot): array
    {
        $normalized = [];
        foreach ($snapshot as $account) {
            if (!is_array($account)) {
                continue;
            }

            $name = trim((string) ($account['name'] ?? ''));
            $role = trim((string) ($account['role'] ?? ''));
            $email = strtolower(trim((string) ($account['email'] ?? '')));
            $password = (string) ($account['password'] ?? '');
            $inviteConfirmed = $role === 'Admin' ? true : (bool) ($account['inviteConfirmed'] ?? false);

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

    public static function loadAccounts(): array
    {
        try {
            $rows = DB::table('staff')
                ->select('full_name', 'role', 'email', 'password_hash', 'created_at')
                ->orderBy('id', 'asc')
                ->get()
                ->all();

            $accounts = [];
            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row->email ?? '')));
                $role = trim((string) ($row->role ?? '')) ?: 'Staff';
                $inviteConfirmed = true;

                if (in_array($role, ['Cashier', 'Inventory Manager'], true)) {
                    $token = DB::table('staff_invite_tokens')
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->whereRaw('LOWER(role) = ?', [strtolower($role)])
                        ->first();

                    $inviteConfirmed = $token ? false : true;
                }

                $accounts[] = [
                    'name' => trim((string) ($row->full_name ?? '')) ?: 'Staff',
                    'role' => $role,
                    'email' => $email,
                    // Do not expose password hashes as plaintext to clients.
                    'password' => '',
                    'inviteConfirmed' => $inviteConfirmed,
                ];
            }

            return self::normalizeAccounts($accounts);
        } catch (\Throwable $error) {
            return self::normalizeAccounts([]);
        }
    }

    public static function saveAccounts(array $accounts): void
    {
        $normalized = self::normalizeAccounts($accounts);

        foreach ($normalized as $account) {
            $email = strtolower(trim((string) ($account['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $name = trim((string) ($account['name'] ?? '')) ?: 'Staff';
            $role = trim((string) ($account['role'] ?? '')) ?: 'Staff';

            $passwordPlain = isset($account['password']) ? (string) $account['password'] : '';
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

            // Upsert into staff table using email as key. If password not
            // provided, do not overwrite existing password_hash.
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
            return strtolower(trim((string) $account['email']));
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
        if (count($normalizedEmails) === 0) {
            DB::table('staff_invite_tokens')->delete();
        } else {
            DB::table('staff_invite_tokens')
                ->whereNotIn(DB::raw('LOWER(email)'), $normalizedEmails)
                ->delete();
        }
    }

    public static function getAdminAccount(array $accounts): ?array
    {
        foreach ($accounts as $account) {
            if (($account['role'] ?? '') === 'Admin') {
                return $account;
            }
        }

        return null;
    }

    public static function generateVerificationCode(int $length = 6): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i += 1) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
