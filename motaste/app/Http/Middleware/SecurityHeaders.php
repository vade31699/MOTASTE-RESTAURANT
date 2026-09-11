<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Request attribute (and Blade variable) carrying the per-response CSP
     * nonce, so views and tests can reference the exact value used in the
     * header without re-deriving it.
     */
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    public function handle(Request $request, Closure $next): Response
    {
        // Generate the nonce BEFORE the response is rendered: the header below
        // trusts exactly this value, and the two inline scripts the app still
        // needs — Ziggy's @routes table in app.blade.php (wired up with
        // @routes(null, $cspNonce)) and @vite's asset-prefetch snippet — have
        // to carry it. This is what lets script-src drop 'unsafe-inline'
        // without breaking route() in the Vue pages.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        View::share('cspNonce', $nonce);
        // @vite emits its own inline asset-prefetch script; hand it the same
        // nonce so Laravel's generated tags are trusted too.
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Force HTTPS in browsers for a year (and all subdomains). The platform
        // already serves everything over HTTPS, so this only hardens clients
        // against downgrade/stripping attacks.
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Content-Security-Policy for the routed HTML pages (home.html,
        // staff.html, Inertia pages). Scripts are same-origin, explicit CDNs,
        // or tagged with this response's nonce — no 'unsafe-inline', so an
        // injected <script> block or inline event-handler attribute cannot
        // execute (the pages use external scripts + addEventListener instead).
        // Inline STYLES are still allowed because the app sets element.style.*
        // dynamically and the pages carry style attributes. CDN allowances
        // cover Font Awesome / Boxicons / Google Fonts / xlsx used by the
        // pages, and the embedded Google Maps iframe. Note maps.google.com
        // redirects (301) to www.google.com/maps/embed, so both hosts must be
        // in frame-src or the map silently fails to load. Google reCAPTCHA v2
        // is required for the staff login page: the api.js script, the
        // checkbox iframe, and its siteverify calls all use
        // google.com/recaptcha (recaptcha.net is the regional fallback, so it
        // is allowed for scripts too).
        $response->headers->set('Content-Security-Policy', "default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com https://www.recaptcha.net; "
            . "style-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com; "
            . "img-src 'self' data: https://maps.google.com https://www.google.com https://www.recaptcha.net; "
            . "frame-src https://maps.google.com https://www.google.com https://www.recaptcha.net; "
            . "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com https://www.google.com https://www.gstatic.com https://www.recaptcha.net; "
            . "base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors 'self'");

        return $response;
    }
}
