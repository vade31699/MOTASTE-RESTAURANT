<?php

// Include-only helper: never reaches the browser as an entry script. When this
// file IS the request's entry script the request is direct HTTP access, so it
// is refused with 403. Legitimate includes (endpoints, console, tests) always
// have a different SCRIPT_FILENAME.
if (
    (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__)
    || (isset($_SERVER['PHP_SELF']) && realpath((string) $_SERVER['PHP_SELF']) === __FILE__)
) {
    http_response_code(403);
    exit;
}

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

    // Blunt any script-injection / data-exfiltration attempts. Scripts are
    // same-origin only and inline scripts are blocked (no 'unsafe-inline') so
    // an injected <script> or on*= attribute cannot execute. Inline styles are
    // still allowed because the app sets element.style.* dynamically. No
    // third-party scripts are loaded on API responses, so a strict default is
    // safe here.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}
