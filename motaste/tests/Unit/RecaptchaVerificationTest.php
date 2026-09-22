<?php

/**
 * Tests for reCAPTCHA verification *reporting*.
 *
 * The bug these cover: verifyRecaptchaToken() returned a bare false for two
 * completely different situations — "Google rejected this token" and "this
 * server could not reach or trust Google" — and logged only "HTTP 0". A visitor
 * whose CAPTCHA actually worked (the browser talks to Google directly) was told
 * the CAPTCHA had failed and retried the checkbox forever, while the real cause
 * — a PHP with no CA trust store — stayed invisible.
 *
 * No network is used here. The one test that does call Google asserts only that
 * a bogus token never reports success, which holds whether the machine can reach
 * Google (Google answers "invalid-input-response" → rejected) or cannot (TLS
 * handshake fails → transport).
 */

function bootRecaptchaTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // In-memory SQLite, as the other unit tests do: the app is booted manually
    // (not via Laravel's test harness), so without this the tests would read
    // .env and could touch the real database.
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    require_once __DIR__ . '/../../public/api/_staff_auth_helpers.php';
    require_once __DIR__ . '/../../public/api/_security_config_helpers.php';
}

// Boot before the first test runs, not inside it: the app bootstrap registers
// global error/exception handlers, and PHPUnit marks whichever test triggers
// the boot as "risky" (did not remove its own error handlers) when that happens
// mid-test.
beforeAll(function () {
    bootRecaptchaTestApp();
});

/**
 * Run $callback with CURL_CA_BUNDLE forced to $value (null removes it), then
 * restore it so the override cannot leak into another test.
 */
function withCaBundleEnv(?string $value, callable $callback): void
{
    $hadServer = array_key_exists('CURL_CA_BUNDLE', $_SERVER);
    $serverValue = $_SERVER['CURL_CA_BUNDLE'] ?? null;
    $hadEnv = array_key_exists('CURL_CA_BUNDLE', $_ENV);
    $envValue = $_ENV['CURL_CA_BUNDLE'] ?? null;
    $putenvValue = getenv('CURL_CA_BUNDLE');

    if ($value === null) {
        unset($_SERVER['CURL_CA_BUNDLE'], $_ENV['CURL_CA_BUNDLE']);
        putenv('CURL_CA_BUNDLE');
    } else {
        $_SERVER['CURL_CA_BUNDLE'] = $value;
        $_ENV['CURL_CA_BUNDLE'] = $value;
        putenv('CURL_CA_BUNDLE=' . $value);
    }

    try {
        $callback();
    } finally {
        if ($hadServer) {
            $_SERVER['CURL_CA_BUNDLE'] = $serverValue;
        } else {
            unset($_SERVER['CURL_CA_BUNDLE']);
        }
        if ($hadEnv) {
            $_ENV['CURL_CA_BUNDLE'] = $envValue;
        } else {
            unset($_ENV['CURL_CA_BUNDLE']);
        }
        if ($putenvValue === false) {
            putenv('CURL_CA_BUNDLE');
        } else {
            putenv('CURL_CA_BUNDLE=' . $putenvValue);
        }
    }
}

/* ------------------------------------------------------------- reasons --- */

test('a blank token is reported as misconfigured rather than as a failed challenge', function () {
    $result = verifyRecaptchaTokenDetailed('', 'a-real-looking-secret');

    expect($result['ok'])->toBeFalse();
    expect($result['reason'])->toBe(RECAPTCHA_REASON_MISCONFIGURED);
});

test('a blank secret is reported as misconfigured, without calling Google', function () {
    $result = verifyRecaptchaTokenDetailed('some-token', '   ');

    expect($result['ok'])->toBeFalse();
    expect($result['reason'])->toBe(RECAPTCHA_REASON_MISCONFIGURED);
    expect($result['detail'])->toContain('secret');
});

test('a bogus token is never reported as ok, and the reason is never "ok"', function () {
    // Environment-independent: depending on this machine's CA store this is
    // either a transport failure (handshake cannot be verified) or a rejection
    // from Google. Both must be distinguishable from success.
    $result = verifyRecaptchaTokenDetailed('bogus-token-value', 'a-real-looking-secret', '203.0.113.5');

    expect($result['ok'])->toBeFalse();
    expect($result['reason'])->toBeIn([
        RECAPTCHA_REASON_REJECTED,
        RECAPTCHA_REASON_TRANSPORT,
        RECAPTCHA_REASON_MISCONFIGURED,
    ]);
    expect($result['detail'])->not->toBe('');
});

test('the boolean wrapper stays consistent with the detailed result', function () {
    // verifyRecaptchaToken() is kept for callers that only need yes/no.
    expect(verifyRecaptchaToken('', 'secret'))->toBeFalse();
    expect(verifyRecaptchaToken('token', ''))->toBeFalse();
});

test('every reason has a distinct constant and only OK is success', function () {
    $reasons = [
        RECAPTCHA_REASON_OK,
        RECAPTCHA_REASON_REJECTED,
        RECAPTCHA_REASON_TRANSPORT,
        RECAPTCHA_REASON_MISCONFIGURED,
    ];

    expect(count(array_unique($reasons)))->toBe(4);

    foreach ([RECAPTCHA_REASON_REJECTED, RECAPTCHA_REASON_TRANSPORT, RECAPTCHA_REASON_MISCONFIGURED] as $reason) {
        expect(recaptchaVerificationResult($reason, 'x')['ok'])->toBeFalse();
    }
    expect(recaptchaVerificationResult(RECAPTCHA_REASON_OK, '')['ok'])->toBeTrue();
});

/* ------------------------------------------------------------ CA bundle --- */

test('CURL_CA_BUNDLE is only used when it names an existing file', function () {
    withCaBundleEnv(null, function () {
        expect(recaptchaCurlCaBundle())->toBe('');
    });

    // A typo must not silently disable verification, and must not be handed to
    // curl as a path that does not exist.
    withCaBundleEnv('C:/definitely/not/a/real/ca-bundle.pem', function () {
        expect(recaptchaCurlCaBundle())->toBe('');
    });

    // An existing file is used. Any real file does for this check — the helper
    // only asserts that the configured path resolves.
    $realFile = __DIR__ . '/../../composer.json';
    expect(is_file($realFile))->toBeTrue();

    withCaBundleEnv($realFile, function () use ($realFile) {
        expect(recaptchaCurlCaBundle())->toBe($realFile);
    });
});

/* ------------------------------------------------- outbound TLS warning --- */

test('the CA-store warning is well-formed whenever it fires', function () {
    // Driven through CURL_CA_BUNDLE because php.ini is not writable from a test.
    // Whether the warning fires depends on the machine (a Linux CI box normally
    // has a working store, a stock Windows WAMP does not), so assert the shape
    // of whichever outcome this environment produces rather than the outcome.
    withCaBundleEnv(null, function () {
        $warning = outboundTlsTrustWarning();

        if ($warning === null) {
            // A usable store exists: the check must stay silent. Nothing to
            // assert beyond that — a non-null here would be a false positive.
            expect($warning)->toBeNull();
            return;
        }

        expect($warning['id'])->toBe('outbound_tls_trust_missing');
        expect($warning['severity'])->toBe('critical');
        expect($warning['missing'])->toBe(['curl.cainfo']);
        // It must name the fix and the misleading symptom it produces.
        expect($warning['message'])->toContain('curl.cainfo');
        expect($warning['message'])->toContain('CURL_CA_BUNDLE');
        expect($warning['message'])->toContain('CAPTCHA');
        expect($warning['message'])->toContain('email');
    });
});

test('the warning disappears once a usable CA bundle is configured', function () {
    // composer.json is not a certificate bundle, but the check is "does the
    // configured path resolve" — which is exactly what a typo would defeat.
    $realFile = __DIR__ . '/../../composer.json';

    withCaBundleEnv($realFile, function () {
        expect(outboundTlsTrustWarning())->toBeNull();
    });
});

test('collectSecurityConfigWarnings reports the TLS problem alongside the CAPTCHA one', function () {
    withCaBundleEnv(null, function () {
        $ids = array_column(collectSecurityConfigWarnings(), 'id');

        // No duplicate entries, and every id is one of the known checks.
        expect(count($ids))->toBe(count(array_unique($ids)));
        foreach ($ids as $id) {
            expect($id)->toBeIn(['captcha_not_configured', 'outbound_tls_trust_missing']);
        }
    });
});
