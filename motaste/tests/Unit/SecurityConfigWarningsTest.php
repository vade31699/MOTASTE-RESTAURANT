<?php

/**
 * Tests for the security-configuration checks behind
 * GET /api/get_security_warnings.php.
 *
 * Scope : these assert the REPORT, not the enforcement. It is
 * authenticate_staff.php that refuses an armed login when the CAPTCHA secret
 * is missing (covered by AuthenticateStaffCaptchaTest). What matters here is
 * that the admin dashboard is told about the degraded protection, and told
 * precisely which variable is at fault so the fix is obvious.
 *
 * The keys are overridden on $_SERVER rather than with putenv(): an entry that
 * exists but is EMPTY is exactly what env() reports for an unset key, and a
 * present entry also stops Laravel's dotenv loader from refilling the value
 * from .env (the loader only writes keys the Env repository does not already
 * contain).
 */

function bootSecurityConfigTestApp(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // Force the in-memory SQLite testing database. The app is booted manually
    // (not through Laravel's test harness), so without this the tests would
    // read .env and could touch the real database.
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

    require_once __DIR__ . '/../../public/api/_security_config_helpers.php';
}

// Boot before the first test runs, not inside it: the app bootstrap registers
// global error/exception handlers, and PHPUnit marks whichever test triggers
// the boot as "risky" (did not remove its own error handlers) when that happens
// mid-test. beforeAll runs outside per-test handler accounting.
beforeAll(function () {
    bootSecurityConfigTestApp();
});

/**
 * Run $callback with the reCAPTCHA keys forced to the given values, restoring
 * the real environment afterwards so the override can never leak into another
 * test that reads env().
 */
function withRecaptchaKeys(?string $siteKey, ?string $secretKey, callable $callback): void
{
    $names = ['RECAPTCHA_V2_SITE_KEY', 'RECAPTCHA_V2_SECRET_KEY'];

    $restore = [];
    foreach ($names as $name) {
        $restore[$name] = [
            'serverSet' => array_key_exists($name, $_SERVER),
            'server' => $_SERVER[$name] ?? null,
            'envSet' => array_key_exists($name, $_ENV),
            'env' => $_ENV[$name] ?? null,
        ];
    }

    $apply = static function (string $name, ?string $value): void {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            return;
        }
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    };

    $apply('RECAPTCHA_V2_SITE_KEY', $siteKey);
    $apply('RECAPTCHA_V2_SECRET_KEY', $secretKey);

    try {
        $callback();
    } finally {
        foreach ($restore as $name => $state) {
            if ($state['serverSet']) {
                $_SERVER[$name] = $state['server'];
            } else {
                unset($_SERVER[$name]);
            }
            if ($state['envSet']) {
                $_ENV[$name] = $state['env'];
            } else {
                unset($_ENV[$name]);
            }
        }
    }
}

test('a fully configured CAPTCHA reports no warnings', function () {
    withRecaptchaKeys('site-key', 'secret-key', function () {
        expect(collectSecurityConfigWarnings())->toBe([]);
    });
});

test('a missing secret key is reported as a critical warning', function () {
    withRecaptchaKeys('site-key', '', function () {
        $warnings = collectSecurityConfigWarnings();

        expect($warnings)->toHaveCount(1);
        expect($warnings[0]['id'])->toBe('captcha_not_configured');
        expect($warnings[0]['severity'])->toBe('critical');
        expect($warnings[0]['missing'])->toBe(['RECAPTCHA_V2_SECRET_KEY']);
        // The message has to name the variable that needs setting.
        expect($warnings[0]['message'])->toContain('RECAPTCHA_V2_SECRET_KEY');
    });
});

test('a missing site key is reported too, because the checkbox cannot render', function () {
    withRecaptchaKeys('', 'secret-key', function () {
        $warnings = collectSecurityConfigWarnings();

        expect($warnings)->toHaveCount(1);
        expect($warnings[0]['missing'])->toBe(['RECAPTCHA_V2_SITE_KEY']);
        expect($warnings[0]['message'])->toContain('RECAPTCHA_V2_SITE_KEY');
    });
});

test('both keys missing reports both variable names in one warning', function () {
    withRecaptchaKeys('', '', function () {
        $warnings = collectSecurityConfigWarnings();

        expect($warnings)->toHaveCount(1);
        expect($warnings[0]['missing'])->toBe(['RECAPTCHA_V2_SITE_KEY', 'RECAPTCHA_V2_SECRET_KEY']);
        expect($warnings[0]['message'])->toContain('RECAPTCHA_V2_SITE_KEY');
        expect($warnings[0]['message'])->toContain('RECAPTCHA_V2_SECRET_KEY');
    });
});

test('whitespace-only keys are treated as missing', function () {
    // A key pasted as spaces is configured as far as the environment is
    // concerned but useless in practice — every call site trims it.
    withRecaptchaKeys('   ', '   ', function () {
        $warnings = collectSecurityConfigWarnings();

        expect($warnings)->toHaveCount(1);
        expect($warnings[0]['missing'])->toBe(['RECAPTCHA_V2_SITE_KEY', 'RECAPTCHA_V2_SECRET_KEY']);
    });
});

test('the report names the variable but never carries its secret value', function () {
    // The dashboard banner is rendered from these strings, so a regression that
    // interpolated the configured key into the message would leak it to every
    // admin page — and into anything that logs the response.
    $secret = 'secret-value-that-must-never-be-serialized';

    withRecaptchaKeys('site-key', $secret, function () use ($secret) {
        expect(json_encode(collectSecurityConfigWarnings()))->not->toContain($secret);
    });

    // Half configured: only the NAME of the missing variable is reported.
    withRecaptchaKeys('', $secret, function () use ($secret) {
        $encoded = json_encode(collectSecurityConfigWarnings());
        expect($encoded)->not->toContain($secret);
        expect($encoded)->toContain('RECAPTCHA_V2_SITE_KEY');
    });
});

test('the override leaves the real environment untouched', function () {
    // Guards the helper above: a leaked override would silently change what
    // every later test in this process sees from env().
    $before = [$_SERVER['RECAPTCHA_V2_SECRET_KEY'] ?? null, $_ENV['RECAPTCHA_V2_SECRET_KEY'] ?? null];

    withRecaptchaKeys('', '', function () {
        expect(($_SERVER['RECAPTCHA_V2_SECRET_KEY'] ?? null))->toBe('');
    });

    expect([$_SERVER['RECAPTCHA_V2_SECRET_KEY'] ?? null, $_ENV['RECAPTCHA_V2_SECRET_KEY'] ?? null])->toBe($before);
});
