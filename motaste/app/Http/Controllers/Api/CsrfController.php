<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Replaces public/api/get_csrf_token.php.
 *
 * Returns Laravel's session CSRF token so the legacy front-end header
 * (X-CSRF-Token) and body key (csrfToken) validate against Laravel's
 * VerifyCsrfToken middleware.
 */
class CsrfController extends Controller
{
    public function token(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'csrfToken' => csrf_token(),
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to generate CSRF token',
            ], 500);
        }
    }
}
