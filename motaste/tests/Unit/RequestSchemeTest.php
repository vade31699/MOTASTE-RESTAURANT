<?php

/**
 * Tests for requestIsSecure() — how the API decides whether to flag its auth
 * cookies Secure.
 *
 * The bug this covers: every API endpoint hand-rolls its cookie parameters, so
 * SESSION_SECURE_COOKIE was ignored there and a cookie was flagged Secure only
 * when $_SERVER['HTTPS'] was set. On a platform that terminates TLS at a load
 * balancer and forwards plain HTTP, PHP never sees HTTPS — so the PHP session
 * cookie and the staff bearer-token cookie both went out WITHOUT Secure.
 *
 * The security-critical assertion is the negative one: a caller who is NOT a
 * trusted proxy must never be able to influence the flag with a forged
 * X-Forwarded-Proto header.
 */

function bootRequestSchemeTestApp(): void
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

    require_once __DIR__ . '/../../public/api/_request_helpers.php';
    require_once __DIR__ . '/../../public/api/csrf_guard.php';
}

// Boot before the first test runs, not inside it: the app bootstrap registers
// global error/exception handlers, and PHPUnit marks whichever test triggers
// the boot as "risky" (did not remove its own error handlers) when that happens
// mid-test.
beforeAll(function () {
    bootRequestSchemeTestApp();
});

/** Every key these tests manipulate, so each one is snapshotted and restored. */
const REQUEST_SCHEME_TEST_KEYS = [
    'APP_ENV',
    'SESSION_SECURE_COOKIE',
    'TRUSTED_PROXY_IPS',
    'HTTPS',
    'REMOTE_ADDR',
    'HTTP_X_FORWARDED_PROTO',
    'SERVER_PORT',
];

/**
 * Run $callback with the given $_SERVER/$_ENV/putenv (and config, when a
 * container is bound) values applied, then restore every managed key.
 *
 * A value of null REMOVES the key. Removing matters as much as setting: Laravel
 * writes .env values into $_SERVER during bootstrap, so "unset" has to mean
 * genuinely absent rather than "left holding the real .env value".
 *
 * config('app.env') is overridden too because the container caches it at
 * bootstrap — without that, a test could not simulate production no matter what
 * it put in $_SERVER.
 */
function withRequestSchemeEnv(array $overrides, callable $callback): void
{
    $hadConfig = false;
    $originalConfigEnv = null;
    try {
        $hadConfig = function_exists('app') && app()->bound('config');
        $originalConfigEnv = $hadConfig ? config('app.env') : null;
    } catch (Throwable $error) {
        $hadConfig = false;
    }

    $snapshot = [];
    foreach (REQUEST_SCHEME_TEST_KEYS as $key) {
        $snapshot[$key] = [
            'serverSet' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
            'envSet' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'putenv' => getenv($key),
        ];
    }

    foreach (REQUEST_SCHEME_TEST_KEYS as $key) {
        if (!array_key_exists($key, $overrides)) {
            continue;
        }

        $value = $overrides[$key];

        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key); // no '=' — removes the variable entirely
            continue;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    if ($hadConfig && array_key_exists('APP_ENV', $overrides)) {
        config(['app.env' => (string) $overrides['APP_ENV']]);
    }

    try {
        $callback();
    } finally {
        foreach ($snapshot as $key => $state) {
            if ($state['serverSet']) {
                $_SERVER[$key] = $state['server'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($state['envSet']) {
                $_ENV[$key] = $state['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($state['putenv'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $state['putenv']);
            }
        }

        if ($hadConfig) {
            config(['app.env' => (string) $originalConfigEnv]);
        }
    }
}

/* ---------------------------------------------------------------- scheme -- */

test('an explicit HTTPS server variable is honoured', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'HTTPS' => 'on',
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '443',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });
});

test('HTTPS=off is not treated as secure', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'HTTPS' => 'off',
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeFalse();
    });
});

test('SERVER_PORT 443 counts as secure when nothing else says so', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '443',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });
});

/* ------------------------------------------------- the TLS-proxy bug fix -- */

test('production is treated as HTTPS-only even with no signal on the request', function () {
    // The actual deployment case: TLS ends at the load balancer, so PHP sees
    // neither HTTPS nor a recognised proxy address. Without this, the session
    // and bearer-token cookies went out without Secure.
    withRequestSchemeEnv([
        'APP_ENV' => 'production',
        'SESSION_SECURE_COOKIE' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });
});

test('an explicit SESSION_SECURE_COOKIE=false wins over the production default', function () {
    // Explicit operator intent must not be silently overridden: a deployment
    // that declares itself HTTP-only (e.g. a non-TLS internal instance) has to
    // keep working, or the fix would log everyone out of that environment.
    withRequestSchemeEnv([
        'APP_ENV' => 'production',
        'SESSION_SECURE_COOKIE' => 'false',
        'HTTPS' => 'on',
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '443',
    ], function () {
        expect(requestIsSecure())->toBeFalse();
    });
});

test('an explicit SESSION_SECURE_COOKIE=true secures a non-production deployment', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => 'true',
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });
});

/* ------------------------------------------------------- spoof rejection -- */

test('a forged X-Forwarded-Proto from an untrusted peer is ignored', function () {
    // The header is client-supplied. Anyone able to reach the app directly must
    // not be able to influence a cookie flag with it.
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9', // public, not a trusted proxy
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeFalse();
    });
});

test('a forged X-Forwarded-Proto is ignored when the peer is unknown', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => null, // CLI / no peer recorded
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeFalse();
    });
});

test('only the literal https token is accepted from a trusted proxy', function () {
    foreach (['https://evil.example', 'HTTPS-ish', 'ht tp', 'http'] as $badValue) {
        withRequestSchemeEnv([
            'APP_ENV' => 'local',
            'SESSION_SECURE_COOKIE' => null,
            'HTTPS' => null,
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => $badValue,
            'SERVER_PORT' => '80',
        ], function () use ($badValue) {
            expect(requestIsSecure())->toBeFalse("expected '{$badValue}' to be rejected");
        });
    }
});

/* --------------------------------------------------------- trusted peers -- */

test('X-Forwarded-Proto is believed from a private proxy by default', function () {
    foreach (['10.1.2.3', '172.16.5.5', '192.168.0.9', '127.0.0.1', '169.254.1.1', '::1', 'fd00::1'] as $peer) {
        withRequestSchemeEnv([
            'APP_ENV' => 'local',
            'SESSION_SECURE_COOKIE' => null,
            'TRUSTED_PROXY_IPS' => null,
            'HTTPS' => null,
            'REMOTE_ADDR' => $peer,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'SERVER_PORT' => '80',
        ], function () use ($peer) {
            expect(requestIsSecure())->toBeTrue("expected {$peer} to be a trusted proxy");
        });
    }
});

test('a public proxy is only trusted when listed explicitly', function () {
    $peer = '198.51.100.7';

    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'TRUSTED_PROXY_IPS' => '198.51.100.7',
        'HTTPS' => null,
        'REMOTE_ADDR' => $peer,
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });

    // Same peer, a list that does not include it: the explicit list REPLACES
    // the defaults, so the header must be ignored again.
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'TRUSTED_PROXY_IPS' => '198.51.100.8, 203.0.113.0/24',
        'HTTPS' => null,
        'REMOTE_ADDR' => $peer,
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeFalse();
    });
});

test('a wildcard trusted-proxy list trusts any peer', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'TRUSTED_PROXY_IPS' => '*',
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });
});

test('the left-most forwarded protocol is the one that counts', function () {
    // Each proxy appends the scheme it observed, so the first entry is what the
    // original client used — matching Symfony's convention.
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'TRUSTED_PROXY_IPS' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'https, http',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });

    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'TRUSTED_PROXY_IPS' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => ' http , https ',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeFalse();
    });
});

test('an IPv4-mapped IPv6 peer still matches an IPv4 trusted range', function () {
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
        'TRUSTED_PROXY_IPS' => null,
        'HTTPS' => null,
        'REMOTE_ADDR' => '::ffff:192.168.1.20',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT' => '80',
    ], function () {
        expect(requestIsSecure())->toBeTrue();
    });
});

/* ------------------------------------------------------------ CIDR match -- */

test('CIDR matching handles boundaries, families and junk input', function () {
    expect(ipMatchesCidrRange('10.0.0.1', '10.0.0.0/8'))->toBeTrue();
    expect(ipMatchesCidrRange('10.255.255.255', '10.0.0.0/8'))->toBeTrue();
    // One past the end of the range.
    expect(ipMatchesCidrRange('11.0.0.0', '10.0.0.0/8'))->toBeFalse();
    // Just inside/outside a /12 boundary.
    expect(ipMatchesCidrRange('172.31.255.255', '172.16.0.0/12'))->toBeTrue();
    expect(ipMatchesCidrRange('172.32.0.0', '172.16.0.0/12'))->toBeFalse();
    // Non-byte-aligned prefix.
    expect(ipMatchesCidrRange('192.168.1.7', '192.168.1.0/25'))->toBeTrue();
    expect(ipMatchesCidrRange('192.168.1.200', '192.168.1.0/25'))->toBeFalse();
    // A bare address with no prefix is an exact match.
    expect(ipMatchesCidrRange('203.0.113.5', '203.0.113.5'))->toBeTrue();
    expect(ipMatchesCidrRange('203.0.113.6', '203.0.113.5'))->toBeFalse();
    // IPv6.
    expect(ipMatchesCidrRange('fd00::1', 'fc00::/7'))->toBeTrue();
    expect(ipMatchesCidrRange('::1', '::1/128'))->toBeTrue();
    // Family mismatch is never a match, and junk never throws.
    expect(ipMatchesCidrRange('10.0.0.1', 'fc00::/7'))->toBeFalse();
    expect(ipMatchesCidrRange('not-an-ip', '10.0.0.0/8'))->toBeFalse();
    expect(ipMatchesCidrRange('10.0.0.1', 'not-a-range'))->toBeFalse();
    expect(ipMatchesCidrRange('10.0.0.1', '10.0.0.0/abc'))->toBeFalse();
    expect(ipMatchesCidrRange('10.0.0.1', '10.0.0.0/33'))->toBeFalse();
    expect(ipMatchesCidrRange('', '10.0.0.0/8'))->toBeFalse();
    expect(ipMatchesCidrRange('10.0.0.1', ''))->toBeFalse();
});

/* ------------------------------------------------------------- wiring ---- */

test('the session cookie Secure flag follows requestIsSecure()', function () {
    // Proves the resolver actually reached the cookie params: this is the code
    // path that used to read $_SERVER['HTTPS'] directly.
    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => 'true',
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '80',
    ], function () {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => false]);

        ensureSessionForCsrf();

        expect(session_get_cookie_params()['secure'])->toBeTrue();
        expect(session_get_cookie_params()['httponly'])->toBeTrue();
        session_write_close();
    });

    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => 'false',
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '80',
    ], function () {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true]);

        ensureSessionForCsrf();

        expect(session_get_cookie_params()['secure'])->toBeFalse();
        session_write_close();
    });
});

test('the bearer-token cookie helper runs without a fatal on the new require', function () {
    // setStaffSessionTokenCookie() pulls in _request_helpers.php itself via a
    // guarded require_once; if that path were wrong the call would fatal here
    // rather than in production. The CLI SAPI collects no headers, so the
    // cookie decision itself is covered by the structural guards below and by
    // the session-cookie test above.
    require_once __DIR__ . '/../../public/api/_staff_auth_helpers.php';

    withRequestSchemeEnv([
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => 'true',
        'HTTPS' => null,
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => null,
        'SERVER_PORT' => '80',
    ], function () {
        setStaffSessionTokenCookie('test-token-value');
        setStaffSessionTokenCookie(null); // clear path

        expect(function_exists('setStaffSessionTokenCookie'))->toBeTrue();
        expect(requestIsSecure())->toBeTrue();
    });
});

/* ------------------------------------------------------ structural guards -- */

test('no API file reads $_SERVER[HTTPS] except the resolver', function () {
    // Same spirit as PreparedStatementGuardTest: keep the fix from rotting. A
    // direct $_SERVER['HTTPS'] read is exactly the bug this file exists to
    // prevent, and it is the kind of line that gets copied into the next
    // endpoint, so a structural assertion is worth more than runtime coverage.
    $files = glob(__DIR__ . '/../../public/api/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    $offenders = [];
    foreach ($files as $file) {
        $name = basename($file);
        if ($name === '_request_helpers.php') {
            continue; // the one place allowed to inspect it
        }

        $source = (string) file_get_contents($file);

        foreach (explode("\n", $source) as $line) {
            $trimmed = ltrim($line);

            // Skip comment lines: the helpers legitimately mention
            // $_SERVER['HTTPS'] in their explanations of why they no longer
            // read it, and a guard that trips on prose is a guard people
            // disable instead of fixing.
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match('/\$_SERVER\s*\[\s*["\']HTTPS["\']\s*\]/', $line) === 1) {
                $offenders[] = $name;
                break;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('every API cookie Secure flag derives from the resolver', function () {
    // Any 'secure' => value must be the resolver, or a variable already
    // resolved from it — never a fresh inline scheme check.
    $derivedExpressions = ['requestIsSecure()', '$secure', "\$cookieParams['secure']"];

    $unexpected = [];
    $checked = 0;

    foreach (['csrf_guard.php', '_staff_auth_helpers.php'] as $name) {
        $source = (string) file_get_contents(__DIR__ . '/../../public/api/' . $name);

        preg_match_all("/'secure'\\s*=>\\s*([^,\n]+)/", $source, $matches);
        $expressions = array_map('trim', $matches[1]);
        $checked += count($expressions);

        foreach ($expressions as $expression) {
            if (!in_array($expression, $derivedExpressions, true)) {
                // Naming the file and the expression in the failure makes the
                // fix obvious without needing a per-assertion message.
                $unexpected[] = $name . " sets Secure from '" . $expression . "'";
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(4); // 1 in csrf_guard, 3 in the staff helper
    expect($unexpected)->toBe([]);
});
