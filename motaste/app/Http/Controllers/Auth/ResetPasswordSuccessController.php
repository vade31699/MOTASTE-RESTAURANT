<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Response;

class ResetPasswordSuccessController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ResetPasswordSuccess', [
            'email' => $request->email ?? '',
        ]);
    }
}
