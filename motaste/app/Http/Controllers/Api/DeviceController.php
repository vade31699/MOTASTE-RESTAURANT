<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated trusted-device endpoints.
 *
 * Replaces: public/api/get_trusted_devices.php, revoke_trusted_device.php
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (!is_array($body)) {
            $body = $request->all();
        }
        $email = strtolower(trim((string) ($request->query('email', $body['email'] ?? ''))));
        $deviceToken = trim((string) ($request->query('deviceToken', $body['deviceToken'] ?? '')));

        if ($email === '') {
            return response()->json(['success' => false, 'error' => 'Email is required.'], 400);
        }

        try {
            $currentFingerprint = $deviceToken !== '' ? DeviceAuthService::computeFingerprint($email, $deviceToken) : '';

            $devices = DB::table('trusted_devices')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orderByDesc('last_seen_at')
                ->limit(100)
                ->get();

            $list = $devices->map(function ($row) use ($currentFingerprint) {
                $label = (string) ($row->device_label ?? '');
                if ($label === '' && $row->user_agent) {
                    $label = DeviceAuthService::resolveDeviceLabel((string) $row->user_agent);
                }

                return [
                    'id' => (int) ($row->id ?? 0),
                    'device_label' => $label !== '' ? $label : 'Unknown device',
                    'fingerprint' => (string) ($row->fingerprint ?? ''),
                    'ip_address' => (string) ($row->ip_address ?? ''),
                    'first_seen_at' => (string) ($row->first_seen_at ?? ''),
                    'last_seen_at' => (string) ($row->last_seen_at ?? ''),
                    'is_current' => $currentFingerprint !== '' && hash_equals($currentFingerprint, (string) ($row->fingerprint ?? '')),
                ];
            })->values()->all();

            return response()->json(['success' => true, 'devices' => $list]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to load trusted devices', 'details' => $error->getMessage()], 500);
        }
    }

    public function revoke(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $fingerprint = trim((string) ($input['fingerprint'] ?? ''));
        $deviceToken = trim((string) ($input['deviceToken'] ?? ''));

        if ($email === '' || $fingerprint === '') {
            return response()->json(['success' => false, 'error' => 'Email and device fingerprint are required.'], 400);
        }

        // Refuse to revoke the device currently in use.
        if ($deviceToken !== '') {
            $currentFingerprint = DeviceAuthService::computeFingerprint($email, $deviceToken);
            if ($currentFingerprint !== '' && hash_equals($currentFingerprint, $fingerprint)) {
                return response()->json(['success' => false, 'error' => 'You cannot revoke the device you are currently using.'], 400);
            }
        }

        try {
            $deleted = DB::table('trusted_devices')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('fingerprint', $fingerprint)
                ->delete();

            // Also clear any pending verification tokens for the revoked device.
            DB::table('login_verification_tokens')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('fingerprint', $fingerprint)
                ->delete();

            return response()->json(['success' => true, 'revoked' => (int) $deleted]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to revoke trusted device', 'details' => $error->getMessage()], 500);
        }
    }
}
