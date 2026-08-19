<?php

/**
 * Emit conservative security response headers. Safe to call from any endpoint
 * before output; a no-op once headers have already been sent.
 */
function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // Force HTTPS in browsers for a year (and all subdomains).
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // Blunt any script-injection / data-exfiltration attempts. Scripts and
    // styles are same-origin only; inline styles are allowed because the app
    // sets element.style.* dynamically. No third-party scripts are loaded on
    // API responses, so a strict default is safe here.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}
