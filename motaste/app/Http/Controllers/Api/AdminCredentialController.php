<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailService;
use App\Services\StaffAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated admin credentials endpoints.
 *
 * Replaces: public/api/get_admin_credentials.php,
 * request_admin_credentials_change.php, confirm_admin_credentials_change.php
 */
class AdminCredentialController extends Controller
{
    public function get(Request $request): JsonResponse
    {
        try {
            $accounts = StaffAccountService::loadAccounts();

            $admin = StaffAccountService::getAdminAccount($accounts);
            if (!$admin) {
                return response()->json(['success' => false, 'error' => 'Admin account is missing'], 500);
            }

            return response()->json([
                'success' => true,
                'credentials' => [
                    'email' => $admin['email'],
                ],
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load admin credentials', 'details' => $error->getMessage()], 500);
        }
    }

    public function requestChange(Request $request): JsonResponse
    {
        $currentEmail = strtolower(trim((string) $request->input('currentEmail', '')));
        $currentPassword = (string) $request->input('currentPassword', '');
        $newEmail = strtolower(trim((string) $request->input('newEmail', '')));
        $newPassword = (string) $request->input('newPassword', '');

        if ($currentEmail === '' || $currentPassword === '') {
            return response()->json(['success' => false, 'error' => 'Current email and password are required'], 400);
        }

        if ($newEmail === '' && $newPassword === '') {
            return response()->json(['success' => false, 'error' => 'A new email or password is required'], 400);
        }

        if ($newEmail !== '' && !preg_match('/@gmail\.com$/', $newEmail)) {
            return response()->json(['success' => false, 'error' => 'Admin email must be a Gmail address'], 422);
        }

        if ($newPassword !== '' && mb_strlen($newPassword) < 8) {
            return response()->json(['success' => false, 'error' => 'Admin password must be at least 8 characters'], 422);
        }

        try {
            // Validate current admin credentials against the staff table.
            $adminRow = DB::table('staff')->whereRaw('LOWER(email) = ?', [$currentEmail])->first();
            if (!$adminRow || empty($adminRow->password_hash) || !password_verify($currentPassword, $adminRow->password_hash)) {
                return response()->json(['success' => false, 'error' => 'Current admin credentials are invalid'], 403);
            }

            $code = StaffAccountService::generateVerificationCode(6);
            $codeHash = hash('sha256', $code);
            $expiresAt = now()->addMinutes(10);
            $pendingEmail = $newEmail !== '' ? $newEmail : $currentEmail;

            DB::table('admin_credential_change_tokens')
                ->where('current_email', $currentEmail)
                ->delete();

            DB::table('admin_credential_change_tokens')->insert([
                'current_email' => $currentEmail,
                'code_hash' => $codeHash,
                'pending_email' => $pendingEmail,
                'pending_password' => $newPassword,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $emailBody = "MOTASTE admin credentials change request\n\n"
                ."Verification code: {$code}\n"
                .'Expires: '.$expiresAt->toDateTimeString()."\n\n"
                ."If this was not requested by you, ignore this message immediately.";

            $emailResult = EmailService::send($currentEmail, 'MOTASTE Admin Credentials Verification Code', $emailBody);
            if (!$emailResult['success']) {
                return response()->json(['success' => false, 'error' => 'Unable to send verification email', 'details' => $emailResult['error'] ?? 'Unknown mail error'], 500);
            }

            return response()->json([
                'success' => true,
                'warning' => $emailResult['warning'] ?? null,
                'mailDriver' => $emailResult['driver'] ?? null,
                'delivered' => array_key_exists('delivered', $emailResult) ? (bool) $emailResult['delivered'] : true,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to request credentials change', 'details' => $error->getMessage()], 500);
        }
    }

    public function confirmChange(Request $request): JsonResponse
    {
        $currentEmail = strtolower(trim((string) $request->input('currentEmail', '')));
        $currentPassword = (string) $request->input('currentPassword', '');
        $code = trim((string) $request->input('code', ''));

        if ($currentEmail === '' || $currentPassword === '' || $code === '') {
            return response()->json(['success' => false, 'error' => 'Current credentials and verification code are required'], 400);
        }

        try {
            // Validate current admin credentials.
            $adminRow = DB::table('staff')->whereRaw('LOWER(email) = ?', [$currentEmail])->first();
            if (!$adminRow || empty($adminRow->password_hash) || !password_verify($currentPassword, $adminRow->password_hash)) {
                return response()->json(['success' => false, 'error' => 'Current admin credentials are invalid'], 403);
            }

            $token = DB::table('admin_credential_change_tokens')
                ->where('current_email', $currentEmail)
                ->orderBy('id', 'desc')
                ->first();

            if (!$token) {
                return response()->json(['success' => false, 'error' => 'No pending credentials change found'], 404);
            }

            if (now()->greaterThan($token->expires_at)) {
                DB::table('admin_credential_change_tokens')->where('id', $token->id)->delete();

                return response()->json(['success' => false, 'error' => 'Verification code expired'], 410);
            }

            $hashedCode = hash('sha256', $code);
            if (!hash_equals((string) $token->code_hash, $hashedCode)) {
                return response()->json(['success' => false, 'error' => 'Invalid verification code'], 403);
            }

            $newEmail = strtolower(trim((string) $token->pending_email));
            $newPassword = (string) $token->pending_password;
            $update = ['updated_at' => now()];

            if ($newEmail !== '' && $newEmail !== strtolower(trim((string) $adminRow->email))) {
                $update['email'] = $newEmail;
            }

            if ($newPassword !== '') {
                $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            DB::table('staff')->where('id', $adminRow->id)->update($update);

            DB::table('admin_credential_change_tokens')->where('id', $token->id)->delete();

            return response()->json([
                'success' => true,
                'accounts' => StaffAccountService::loadAccounts(),
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to confirm credentials change', 'details' => $error->getMessage()], 500);
        }
    }
}
