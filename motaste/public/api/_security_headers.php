<?php

/**
 * Emit conservative security response headers. Safe to call from any endpoint
 * before output; a no-op once headers have already been sent.
 *
 * The CSP allows the exact third-party resources the site needs (Boxicons,
 * FontAwesome, Google Fonts, Google Maps embed, CDN chart/xlsx libraries).
 * Inline scripts are intentionally NOT allowed — the two legacy onclick
 * attributes in staff.html were moved to addEventListener in script.js so a
 * strict script-src can be enforced. Inline styles remain allowed because the
 * staff dashboard relies on <style> blocks and style attributes.
 */
const MOTASTE_CSP_HEADER = "default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self'; frame-src https://maps.google.com https://www.google.com; worker-src 'self'; manifest-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'";

/**
 * Return an internal exception message only when the application is in debug
 * mode; otherwise keep stack traces / SQL fragments out of client responses.
 */
function apiErrorDetail(Throwable $error): string
{
    try {
        if (function_exists('config') && (bool) config('app.debug')) {
            return $error->getMessage();
        }
    } catch (Throwable $ignored) {
        // Best effort.
    }

    return '';
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: ' . MOTASTE_CSP_HEADER);

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
