<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceAuthService;
use App\Services\EmailService;
use App\Services\StaffAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated staff authentication.
 *
 * Replaces: public/api/authenticate_staff.php, verify_device_login.php,
 * check_session.php, get_staff_active_count.php, notify_staff_session.php
 *
 * The session is stored in the Laravel session (`staff_session`) so every
 * protected endpoint shares one centralized auth boundary.
 */
class StaffAuthController extends Controller
{
    /**
     * Port of authenticate_staff.php. Response shape is identical so the
     * existing front-end continues to work unchanged.
     */
    public function login(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $selectedRole = trim((string) $request->input('role', ''));
        $deviceToken = trim((string) $request->input('deviceToken', ''));

        if ($email === '' || $password === '') {
            return response()->json(['success' => false, 'error' => 'Email and password are required.'], 400);
        }

        $staffRow = DB::table('staff')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$staffRow || empty($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }

        $role = trim((string) ($staffRow->role ?? ''));
        if ($selectedRole !== '' && $selectedRole !== $role) {
            return response()->json(['success' => false, 'error' => 'Invalid role for this account'], 403);
        }

        // ---- Trusted device recognition -------------------------------------
        $fingerprint = DeviceAuthService::computeFingerprint($email, $deviceToken);

        if (!DeviceAuthService::deviceIsTrusted($email, $fingerprint)) {
            // Rate-limit code issuance: reuse a code created in the last 60s.
            $existingToken = DB::table('login_verification_tokens')
                ->where('email', $email)
                ->where('fingerprint', $fingerprint)
                ->orderBy('id', 'desc')
                ->first();
            $codeAlreadySent = $existingToken
                && now()->lessThan($existingToken->expires_at)
                && now()->diffInSeconds($existingToken->created_at) < 60;

            if ($codeAlreadySent) {
                return response()->json([
                    'success' => false,
                    'needsDeviceVerification' => true,
                    'email' => $email,
                    'role' => $role,
                    'message' => 'New device detected. A verification code was already sent to your email — check your inbox.',
                    'deviceToken' => $deviceToken,
                ]);
            }

            $code = DeviceAuthService::createLoginCode($email, $fingerprint);
            $deviceLabel = DeviceAuthService::resolveDeviceLabel();
            $occurredAt = now()->toDateTimeString();

            $emailBody = "MOTASTE login verification\n\n"
                ."A login was attempted from a new device for this account.\n\n"
                ."Verification code: {$code}\n"
                .'Expires: '.now()->addMinutes(10)->toDateTimeString()."\n\n"
                ."Device: {$deviceLabel}\n"
                .'IP Address: '.DeviceAuthService::resolveClientIpAddress()."\n"
                ."Date/Time: {$occurredAt}\n\n"
                ."Enter this code on the device where you are signing in.\n"
                ."If this was not you, change your password immediately.";

            $emailResult = EmailService::send($email, 'MOTASTE Login Verification Code', $emailBody);

            // Record the challenge for auditing.
            try {
                DB::table('order_activity_logs')->insert([
                    'order_id' => null,
                    'order_number' => null,
                    'action' => 'new_device_login_verification_sent',
                    'actor_role' => $role,
                    'actor_email' => $email,
                    'summary' => 'Verification code emailed for login from an unrecognized device',
                    'details' => json_encode([
                        'device_label' => $deviceLabel,
                        'device_token' => $deviceToken,
                        'ip_address' => DeviceAuthService::resolveClientIpAddress(),
                        'email_delivered' => $emailResult['success'] ?? false,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $logError) {
                // Auditing must never block the verification response.
            }

            $response = [
                'success' => false,
                'needsDeviceVerification' => true,
                'email' => $email,
                'role' => $role,
                'message' => 'New device detected. A verification code was sent to your email.',
                'deviceToken' => $deviceToken,
            ];

            if (!$emailResult['success']) {
                $response['warning'] = 'Verification email could not be delivered: '
                    .($emailResult['error'] ?? 'unknown mail error')
                    .' Check the server logs for the code.';
                error_log('[MOTASTE device verification] code for '.$email.': '.$code);
            }

            return response()->json($response);
        }

        DeviceAuthService::markTrustedSeen($email, $fingerprint);

        $inviteConfirmed = $this->resolveInviteConfirmed($role, $email);

        $this->startStaffSession($staffRow, $role);

        return response()->json([
            'success' => true,
            'role' => $role,
            'email' => strtolower(trim((string) ($staffRow->email ?? ''))),
            'name' => trim((string) ($staffRow->full_name ?? '')),
            'inviteConfirmed' => $inviteConfirmed,
            'deviceVerified' => true,
        ]);
    }

    /**
     * Port of verify_device_login.php.
     */
    public function verifyDevice(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $code = trim((string) $request->input('code', ''));
        $deviceToken = trim((string) $request->input('deviceToken', ''));

        if ($email === '' || $password === '' || $code === '') {
            return response()->json(['success' => false, 'error' => 'Email, password, and verification code are required.'], 400);
        }

        // Re-confirm credentials before trusting the device.
        $staffRow = DB::table('staff')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$staffRow || empty($staffRow->password_hash) || !password_verify($password, $staffRow->password_hash)) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }

        $role = trim((string) ($staffRow->role ?? ''));
        $fingerprint = DeviceAuthService::computeFingerprint($email, $deviceToken);

        if (!DeviceAuthService::verifyLoginCode($email, $fingerprint, $code)) {
            return response()->json(['success' => false, 'error' => 'Invalid or expired verification code'], 403);
        }

        DeviceAuthService::markTrustedSeen($email, $fingerprint);

        $inviteConfirmed = $this->resolveInviteConfirmed($role, $email);

        $this->startStaffSession($staffRow, $role);

        try {
            DB::table('order_activity_logs')->insert([
                'order_id' => null,
                'order_number' => null,
                'action' => 'device_login_verified',
                'actor_role' => $role,
                'actor_email' => strtolower(trim((string) ($staffRow->email ?? ''))),
                'summary' => 'New device verified and added to trusted devices',
                'details' => json_encode([
                    'device_label' => DeviceAuthService::resolveDeviceLabel(),
                    'device_token' => $deviceToken,
                    'ip_address' => DeviceAuthService::resolveClientIpAddress(),
                    'verified_at' => now()->toDateTimeString(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $logError) {
            // Auditing must never block the login response.
        }

        return response()->json([
            'success' => true,
            'role' => $role,
            'email' => strtolower(trim((string) ($staffRow->email ?? ''))),
            'name' => trim((string) ($staffRow->full_name ?? '')),
            'inviteConfirmed' => $inviteConfirmed,
            'deviceVerified' => true,
        ]);
    }

    /**
     * Port of check_session.php.
     */
    public function session(Request $request): JsonResponse
    {
        $staff = $request->session()->get('staff_session');

        if (is_array($staff) && ($staff['email'] ?? '') !== '') {
            return response()->json([
                'authenticated' => true,
                'role' => $staff['role'] ?? '',
                'email' => $staff['email'] ?? '',
                'name' => $staff['name'] ?? '',
            ]);
        }

        return response()->json(['authenticated' => false]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->forget('staff_session');
        $request->session()->regenerate();

        return response()->json(['success' => true]);
    }

    /**
     * Port of get_staff_active_count.php (kept as a heuristic over the
     * sessions table for parity with the legacy dashboard).
     */
    public function activeCount(): JsonResponse
    {
        try {
            $count = DB::table('sessions')
                ->whereRaw('LOWER(payload) LIKE ?', ['%staff%'])
                ->count();

            return response()->json(['success' => true, 'count' => (int) $count]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Unable to determine staff active count', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Port of notify_staff_session.php — emails the admin when a Cashier or
     * Inventory Manager logs in / out.
     */
    public function notify(Request $request): JsonResponse
    {
        $event = strtolower(trim((string) $request->input('event', '')));
        $role = trim((string) $request->input('role', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $occurredAt = trim((string) $request->input('occurredAt', ''));
        $userAgent = trim((string) $request->input('userAgent', ''));

        if (!in_array($event, ['login', 'logout'], true) || $role === '' || $email === '') {
            return response()->json(['success' => false, 'error' => 'Invalid notification payload'], 400);
        }

        if (!in_array($role, ['Cashier', 'Inventory Manager'], true)) {
            return response()->json(['success' => true, 'skipped' => true]);
        }

        try {
            $accounts = StaffAccountService::loadAccounts();
            $admin = StaffAccountService::getAdminAccount($accounts);
            if (!$admin || empty($admin['email'])) {
                return response()->json(['success' => false, 'error' => 'Admin email not configured'], 404);
            }

            $action = $event === 'login' ? 'logged in' : 'logged out';
            $subject = sprintf('MOTASTE Notification: %s %s', $role, ucfirst($event));
            $body = "MOTASTE Staff Session Notification\n\n"
                ."Role: {$role}\n"
                ."Staff Email: {$email}\n"
                ."Action: {$action}\n"
                .'Date/Time: '.($occurredAt !== '' ? $occurredAt : now()->toDateTimeString())."\n"
                ."User Agent: {$userAgent}\n";

            $emailResult = EmailService::send((string) $admin['email'], $subject, $body);
            if (!$emailResult['success']) {
                return response()->json(['success' => false, 'error' => 'Unable to send notification email', 'details' => $emailResult['error'] ?? 'Unknown mail error'], 500);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to notify admin', 'details' => $error->getMessage()], 500);
        }
    }

    private function resolveInviteConfirmed(string $role, string $email): bool
    {
        if (!in_array($role, ['Cashier', 'Inventory Manager'], true)) {
            return true;
        }

        $token = DB::table('staff_invite_tokens')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereRaw('LOWER(role) = ?', [strtolower($role)])
            ->first();

        return $token ? false : true;
    }

    private function startStaffSession(object $staffRow, string $role): void
    {
        session()->regenerate();
        session()->put('staff_session', [
            'role' => $role,
            'email' => strtolower(trim((string) ($staffRow->email ?? ''))),
            'name' => trim((string) ($staffRow->full_name ?? '')),
            'logged_in_at' => now()->toDateTimeString(),
        ]);
    }
}
