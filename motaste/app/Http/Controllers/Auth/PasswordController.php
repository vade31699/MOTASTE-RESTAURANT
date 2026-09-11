<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use App\Rules\NotCommonPassword;
use App\Rules\NotCurrentPassword;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            // NotCurrentPassword stops a signed-in user from "changing" their
            // password to the value that is already set, which would leave the
            // old credential valid.
            'password' => [
                'required',
                Password::defaults(),
                new NotCommonPassword,
                new NotCurrentPassword((string)$request->user()->email),
                'confirmed',
            ],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back();
    }
}
