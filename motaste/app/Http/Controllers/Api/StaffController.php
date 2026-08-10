<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailService;
use App\Services\StaffAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated staff management.
 *
 * Replaces: public/api/list_staff.php, create_staff.php, update_staff.php,
 * delete_staff.php, get_staff_accounts.php, save_staff_accounts.php,
 * send_staff_invite.php, confirm_staff_invite.php
 */
class StaffController extends Controller
{
    /**
     * Port of list_staff.php.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $staffRecords = DB::table('staff')
                ->select('id', 'full_name', 'role', 'email')
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => (int) ($record->id ?? 0),
                        'full_name' => trim((string) ($record->full_name ?? '')),
                        'role' => trim((string) ($record->role ?? '')),
                        'email' => trim((string) ($record->email ?? '')),
                    ];
                })
                ->all();

            return response()->json(['staff' => $staffRecords]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Unable to list staff', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of create_staff.php.
     */
    public function store(Request $request): JsonResponse
    {
        $full = trim((string) $request->input('name', ''));
        $role = trim((string) $request->input('role', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if (!$full || !$role || !$email || !$password) {
            return response()->json(['error' => 'Missing fields'], 400);
        }

        try {
            $insertId = DB::table('staff')->insertGetId([
                'full_name' => $full,
                'role' => $role,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['success' => true, 'id' => $insertId]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Insert failed', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of update_staff.php.
     */
    public function update(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $role = trim((string) $request->input('role', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $currentEmail = trim((string) $request->input('currentEmail', ''));
        $id = (int) $request->input('id', 0);

        if (!$name || !$role || !$email || !$password) {
            return response()->json(['error' => 'Missing fields'], 400);
        }

        $lookupEmail = $currentEmail ?: $email;

        try {
            $query = DB::table('staff');

            if ($id > 0) {
                $query->where('id', $id);
            } else {
                $query->where('email', $lookupEmail);
            }

            $updated = $query->update([
                'full_name' => $name,
                'role' => $role,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => now(),
            ]);

            return response()->json(['success' => true, 'updated' => $updated]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Update failed', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of delete_staff.php.
     */
    public function destroy(Request $request): JsonResponse
    {
        $email = trim((string) $request->input('email', ''));
        if (!$email) {
            return response()->json(['error' => 'Missing email'], 400);
        }

        try {
            $deleted = DB::table('staff')
                ->where('email', $email)
                ->delete();

            return response()->json(['success' => true, 'deleted' => $deleted]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Delete failed', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of get_staff_accounts.php.
     */
    public function accounts(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'accounts' => StaffAccountService::loadAccounts(),
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load staff accounts', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of save_staff_accounts.php.
     */
    public function saveAccounts(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        try {
            StaffAccountService::saveAccounts($input);

            return response()->json(['success' => true]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to save staff accounts', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of send_staff_invite.php.
     */
    public function sendInvite(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $role = trim((string) $request->input('role', ''));
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($name === '' || $role === '' || $email === '') {
            return response()->json(['success' => false, 'error' => 'Name, role, and email are required'], 400);
        }

        if (!in_array($role, ['Cashier', 'Inventory Manager'], true)) {
            return response()->json(['success' => false, 'error' => 'Only Cashier and Inventory Manager are supported'], 422);
        }

        if (!preg_match('/@gmail\.com$/', $email)) {
            return response()->json(['success' => false, 'error' => 'Only Gmail addresses are allowed'], 422);
        }

        try {
            $code = StaffAccountService::generateVerificationCode(6);
            $codeHash = hash('sha256', $code);
            $expiresAt = now()->addMinutes(20);

            DB::table('staff_invite_tokens')
                ->where('email', $email)
                ->where('role', $role)
                ->delete();

            DB::table('staff_invite_tokens')->insert([
                'email' => $email,
                'role' => $role,
                'code_hash' => $codeHash,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $body = "MOTASTE staff account invitation\n\n"
                ."Hello {$name},\n"
                ."You were added as {$role}.\n"
                ."Verification code: {$code}\n"
                .'Code expires: '.$expiresAt->toDateTimeString()."\n\n"
                ."Use this code during your first login to confirm your account.";

            $emailResult = EmailService::send($email, 'MOTASTE Staff Invite Confirmation Code', $body);
            if (!$emailResult['success']) {
                return response()->json(['success' => false, 'error' => 'Unable to send invite email', 'details' => $emailResult['error'] ?? 'Unknown mail error'], 500);
            }

            return response()->json([
                'success' => true,
                'warning' => $emailResult['warning'] ?? null,
                'mailDriver' => $emailResult['driver'] ?? null,
                'delivered' => array_key_exists('delivered', $emailResult) ? (bool) $emailResult['delivered'] : true,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to send invite', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of confirm_staff_invite.php.
     */
    public function confirmInvite(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $role = trim((string) $request->input('role', ''));
        $code = trim((string) $request->input('code', ''));

        if ($email === '' || $role === '' || $code === '') {
            return response()->json(['success' => false, 'error' => 'Email, role, and code are required'], 400);
        }

        try {
            $token = DB::table('staff_invite_tokens')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereRaw('LOWER(role) = ?', [strtolower($role)])
                ->orderBy('id', 'desc')
                ->first();

            if (!$token) {
                return response()->json(['success' => false, 'error' => 'No invite verification found'], 404);
            }

            if (now()->greaterThan($token->expires_at)) {
                DB::table('staff_invite_tokens')->where('id', $token->id)->delete();

                return response()->json(['success' => false, 'error' => 'Invite verification code expired'], 410);
            }

            if (!hash_equals((string) $token->code_hash, hash('sha256', $code))) {
                return response()->json(['success' => false, 'error' => 'Invalid invite verification code'], 403);
            }

            $accounts = StaffAccountService::loadAccounts();
            $updated = false;
            foreach ($accounts as &$account) {
                if (($account['email'] ?? '') === $email && ($account['role'] ?? '') === $role) {
                    $account['inviteConfirmed'] = true;
                    $updated = true;
                    break;
                }
            }
            unset($account);

            if (!$updated) {
                return response()->json(['success' => false, 'error' => 'Staff account not found'], 404);
            }

            StaffAccountService::saveAccounts($accounts);
            DB::table('staff_invite_tokens')->where('id', $token->id)->delete();

            return response()->json([
                'success' => true,
                'accounts' => $accounts,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to confirm invite', 'details' => $error->getMessage()], 500);
        }
    }
}
