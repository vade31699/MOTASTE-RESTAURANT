<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralized staff session guard.
 *
 * After a successful login the Laravel session carries a `staff_session` array
 * ({role, email, name, logged_in_at}). This middleware rejects any request
 * without it, optionally restricting access to a whitelist of roles
 * (usage: `staff.session:Admin` or `staff.session:Admin,Cashier`).
 */
class EnsureStaffSession
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $staff = $request->session()->get('staff_session');

        if (!is_array($staff) || ($staff['email'] ?? '') === '') {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'authenticated' => false,
                    'error' => 'Staff authentication required',
                ], 401);
            }

            return redirect()->guest(route('admin.login'));
        }

        // Optional role whitelist: reject staff whose role is not allowed.
        // Params may arrive as separate args or comma-joined (staff.session:Admin,Cashier).
        $allowed = array_values(array_filter(array_map('trim', explode(',', implode(',', $roles)))));
        if ($allowed !== []) {
            $role = strtolower((string) ($staff['role'] ?? ''));
            $permitted = array_map('strtolower', $allowed);
            if (!in_array($role, $permitted, true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Your role does not have permission to perform this action',
                ], 403);
            }
        }

        return $next($request);
    }
}
