<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
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
        // staff.html, Inertia pages). Scripts/styles are same-origin by
        // default; CDN allowances cover Font Awesome / Boxicons / Google Fonts
        // / xlsx used by the pages, and the embedded Google Maps iframe. Note
        // maps.google.com redirects (301) to www.google.com/maps/embed, so
        // both hosts must be in frame-src or the map silently fails to load.
        $response->headers->set('Content-Security-Policy', "default-src 'self'; "
            . "script-src 'self' https://cdnjs.cloudflare.com; "
            . "style-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com; "
            . "img-src 'self' data: https://maps.google.com https://www.google.com; "
            . "frame-src https://maps.google.com https://www.google.com; "
            . "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com; "
            . "base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors 'self'");

        return $response;
    }
}
