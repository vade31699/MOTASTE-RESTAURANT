<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Server-rendered admin dashboard (the unified staff UI).
 *
 * Serves the login page and the dashboard shell at /admin. All data flows
 * through the consolidated /api/staff/* endpoints so the page itself stays
 * thin and a future SPA can reuse the exact same backend.
 */
class AdminController extends Controller
{
    public function loginPage(Request $request): View|RedirectResponse
    {
        if ($this->hasStaffSession($request)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login', [
            'pageTitle' => 'Staff Login',
        ]);
    }

    public function dashboard(Request $request): View|RedirectResponse
    {
        if (!$this->hasStaffSession($request)) {
            return redirect()->route('admin.login');
        }

        $staff = $request->session()->get('staff_session');

        return view('admin.dashboard', [
            'pageTitle' => 'Admin Dashboard',
            'staff' => $staff,
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('staff_session');
        $request->session()->regenerate();

        return redirect()->route('admin.login');
    }

    private function hasStaffSession(Request $request): bool
    {
        $staff = $request->session()->get('staff_session');

        return is_array($staff) && ($staff['email'] ?? '') !== '';
    }
}
