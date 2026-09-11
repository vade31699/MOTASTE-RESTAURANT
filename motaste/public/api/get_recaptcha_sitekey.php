<?php

/**
 * Serves the public Google reCAPTCHA v2 sitekey so the staff login page can
 * render the checkbox widget with `grecaptcha.render()`. Sitekeys are public
 * identifiers — the secret key stays server-side in RECAPTCHA_V2_SECRET_KEY.
 *
 * staff.html is served as a static file via the /staff Laravel route, so the
 * sitekey cannot be templated into the HTML; script.js fetches it from here
 * (lazily, only when CAPTCHA is actually required) before rendering v2.
 *
 * Returns { sitekey: '' } when reCAPTCHA v2 is not configured — script.js
 * treats an empty sitekey as "CAPTCHA disabled" and shows an explanatory message.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_security_headers.php';
sendSecurityHeaders();

try {
    echo json_encode([
        'success' => true,
        'sitekey' => (string) env('RECAPTCHA_V2_SITE_KEY', ''),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'sitekey' => '',
        'error' => 'Unable to load CAPTCHA configuration',
    ]);
}
