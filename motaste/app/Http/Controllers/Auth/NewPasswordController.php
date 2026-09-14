<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Rules\NotCommonPassword;
use App\Rules\NotCurrentPassword;


class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', [
            'token' => $request->route('token'),
            'email' => $request->email,
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            // Elevated policy (12+ chars) because the reset syncs the new hash
            // into the staff portal's `staff` table.
            // NotCurrentPassword blocks "resetting" to the password that is
            // already in place, which would leave the old credential valid.
            'password' => [
                'required',
                'confirmed',
                Rules\Password::min(12)->mixedCase()->numbers(),
                new NotCommonPassword,
                new NotCurrentPassword((string)$request->email),
            ],
        ]);

        // Only a staff account may be reset through this flow. The token can
        // only have been issued by the staff-scoped verify step, but re-check
        // here so a stale token can never be redeemed for an admin address.
        $rejection = Staff::passwordResetRejection((string) $request->email);
        if ($rejection !== null) {
            throw ValidationException::withMessages([
                'email' => ['We could not find an account with that email address.'],
            ]);
        }

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, also update the `staff` row
        // so the staff portal (authenticate_staff.php, which reads
        // staff.password_hash) accepts the same new password. Only the staff
        // table is synced: the Admin keeps its hash in `admins`, which this
        // staff-only flow never touches.
        if ($status == Password::PASSWORD_RESET) {
            $staffEmail = strtolower(trim($request->email));
            $newHash = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$staffEmail])
                ->value('password');
            if ($newHash) {
                DB::table('staff')
                    ->whereRaw('LOWER(email) = ?', [$staffEmail])
                    ->update(['password_hash' => $newHash]);
            }

            return redirect()->route('password.success');
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
