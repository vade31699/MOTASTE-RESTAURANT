<?php

/**
 * Security-configuration checks, surfaced to the admin dashboard.
 *
 * WHY: several protections deliberately FAIL CLOSED when they are not
 * configured. That is the correct default — an unconfigured verifier must not
 * wave logins through — but it also means a missing environment variable shows
 * up as logins that are refused with no visible cause. Writing the reason to
 * the error log alone is not discoverable by the person who can actually fix
 * it, so the dashboard reports it out loud instead.
 *
 * This helper is strictly read-only: it inspects configuration and describes
 * what is wrong. It never changes behaviour, and callers must not use it to
 * gate a request — the endpoint that enforces a protection is the only thing
 * allowed to decide what happens when that protection is unconfigured.
 */

/**
 * Every configuration problem an admin should act on.
 *
 * Each entry is shaped:
 *
 *   id       - stable machine identifier (the UI de-duplicates on it)
 *   severity - 'critical' | 'warning'
 *   title    - one-line summary for the banner heading
 *   message  - what is wrong, what it breaks, and how to fix it
 *   missing  - the environment variable names that need to be set
 *
 * @return array<int, array{id: string, severity: string, title: string, message: string, missing: array<int, string>}>
 */
function collectSecurityConfigWarnings(): array
{
    $warnings = [];

    $captchaWarning = captchaConfigurationWarning();
    if ($captchaWarning !== null) {
        $warnings[] = $captchaWarning;
    }

    $tlsWarning = outboundTlsTrustWarning();
    if ($tlsWarning !== null) {
        $warnings[] = $tlsWarning;
    }

    return $warnings;
}

/**
 * Warn when PHP has no usable CA trust store.
 *
 * WHY THIS IS WORTH A BANNER: with no trust store every outbound HTTPS request
 * fails certificate verification, and the symptom is deeply misleading. The
 * reCAPTCHA checkbox still renders (the browser talks to Google directly), so a
 * visitor ticks it, gets a token, and then the server reports "CAPTCHA
 * verification failed" — pointing at the visitor, who retries forever, while
 * the real cause is the server. The same missing store also breaks SMTP over
 * TLS (login verification codes) and any database mirror over TLS.
 *
 * A stock WAMP/XAMPP PHP on Windows ships curl.cainfo unset and points OpenSSL
 * at a cert.pem that does not exist, so this is the common local case.
 *
 * Purely a configuration check — it never opens a socket, so it cannot raise a
 * false alarm just because Google was briefly unreachable.
 */
function outboundTlsTrustWarning(): ?array
{
    $candidates = [];

    $curlCainfo = trim((string) ini_get('curl.cainfo'));
    if ($curlCainfo !== '') {
        $candidates['curl.cainfo'] = $curlCainfo;
    }

    $opensslCafile = trim((string) ini_get('openssl.cafile'));
    if ($opensslCafile !== '') {
        $candidates['openssl.cafile'] = $opensslCafile;
    }

    if (function_exists('recaptchaCurlCaBundle')) {
        $caBundle = recaptchaCurlCaBundle();
        if ($caBundle !== '') {
            $candidates['CURL_CA_BUNDLE'] = $caBundle;
        }
    }

    if (function_exists('openssl_get_cert_locations')) {
        $locations = openssl_get_cert_locations();
        $defaultFile = (string) ($locations['default_cert_file'] ?? '');
        if ($defaultFile !== '') {
            $candidates['the OpenSSL default'] = $defaultFile;
        }

        // A hashed CA directory is an alternative to a single file.
        $defaultPath = (string) ($locations['default_cert_dir'] ?? '');
        if ($defaultPath !== '' && is_dir($defaultPath)) {
            return null;
        }
    }

    $capath = trim((string) ini_get('openssl.capath'));
    if ($capath !== '' && is_dir($capath)) {
        return null;
    }

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return null; // A usable bundle exists — nothing to report.
        }
    }

    $configured = [];
    foreach ($candidates as $source => $path) {
        $configured[] = $source . '=' . $path;
    }

    return [
        'id' => 'outbound_tls_trust_missing',
        'severity' => 'critical',
        'title' => 'PHP cannot verify HTTPS connections',
        'message' => 'No usable CA certificate store was found'
            . ($configured === [] ? '' : ' (' . implode('; ', $configured) . ')')
            . ', so every outbound TLS request fails certificate verification. This breaks the '
            . 'staff-login CAPTCHA (the visitor solves it, then verification fails server-side), '
            . 'outbound email over TLS (login verification codes), and any database mirror over TLS. '
            . 'Fix it by setting curl.cainfo (and openssl.cafile) in php.ini to a real CA bundle, '
            . 'or by pointing CURL_CA_BUNDLE at one.',
        'missing' => ['curl.cainfo'],
    ];
}

/**
 * Warn when the staff-login CAPTCHA is unconfigured or only half configured.
 *
 * Both halves matter. The site key renders the checkbox the user has to solve;
 * the secret key verifies the token they submit. With either one missing the
 * armed gate cannot be satisfied by anybody, including a legitimate staff
 * member, which is exactly why authenticate_staff.php refuses those logins
 * rather than letting them through unverified.
 */
function captchaConfigurationWarning(): ?array
{
    $siteKey = trim((string) env('RECAPTCHA_V2_SITE_KEY', ''));
    $secretKey = trim((string) env('RECAPTCHA_V2_SECRET_KEY', ''));

    if ($siteKey !== '' && $secretKey !== '') {
        return null;
    }

    $missing = [];
    if ($siteKey === '') {
        $missing[] = 'RECAPTCHA_V2_SITE_KEY';
    }
    if ($secretKey === '') {
        $missing[] = 'RECAPTCHA_V2_SECRET_KEY';
    }

    return [
        'id' => 'captcha_not_configured',
        'severity' => 'critical',
        'title' => 'Staff-login CAPTCHA is not configured',
        'message' => 'Missing ' . implode(' and ', $missing) . '. Once repeated failed '
            . 'attempts arm the CAPTCHA gate, no token can be validated, so those logins are '
            . 'REFUSED (fail closed) instead of being allowed through unverified — and the '
            . 'checkbox cannot even be displayed, so nobody can clear the gate. Set both '
            . 'reCAPTCHA v2 keys in the environment and redeploy.',
        'missing' => $missing,
    ];
}
