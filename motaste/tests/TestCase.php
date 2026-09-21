<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The global error handlers active before the framework booted.
     *
     * @var list<callable>
     */
    private array $errorHandlers = [];

    /**
     * The global exception handlers active before the framework booted.
     *
     * @var list<callable>
     */
    private array $exceptionHandlers = [];

    protected function setUp(): void
    {
        // phpdotenv's ServerConstAdapter reads $_SERVER before $_ENV/putenv, so
        // ambient shell variables (e.g. Laravel Cloud setting APP_ENV=production
        // and DB_CONNECTION=pgsql) would override phpunit.xml's forced test
        // values and make the test app boot in production against the real DB.
        // Drop them so PHPUnit's <env force="true"> settings take effect.
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            unset($_SERVER[$key]);
        }

        // PHPUnit snapshots the global handler stacks before setUp() and compares
        // them after tearDown(), reporting any difference as a risky test. Laravel
        // deliberately drains them in between: booting the app registers
        // HandleExceptions' own handlers, and its teardown (HandleExceptions::
        // flushState, via InteractsWithTestCaseLifecycle) pops the whole stack
        // before re-enabling PHPUnit's. That identity change is what made all 64
        // Feature tests report "removed error handlers other than its own".
        // Remember what was installed here and put it back once the app is gone,
        // so the stacks PHPUnit compares are the ones it snapshotted.
        $this->errorHandlers = self::activeHandlers('error');
        $this->exceptionHandlers = self::activeHandlers('exception');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Even if the framework's teardown throws, the global stacks must go
            // back to what PHPUnit snapshotted before the next test runs.
            self::restoreHandlers('error', $this->errorHandlers);
            self::restoreHandlers('exception', $this->exceptionHandlers);
        }
    }

    /**
     * Read the global handler stack without leaving it changed.
     *
     * This is the same unwind-and-rewind technique PHPUnit's own (private)
     * activeErrorHandlers() uses: set_*_handler() returns the handler that was
     * active before it, and restore_*_handler() pops the probe again, so the
     * stack can be walked from the top down and then rebuilt in place.
     *
     * @return list<callable>
     */
    private static function activeHandlers(string $kind): array
    {
        $handlers = [];
        $probe = $kind === 'error' ? static fn (): bool => false : static fn (): null => null;

        while (true) {
            $previous = $kind === 'error'
                ? set_error_handler($probe)
                : set_exception_handler($probe);

            self::restore($kind);

            if ($previous === null) {
                break;
            }

            $handlers[] = $previous;

            self::restore($kind);
        }

        $handlers = array_reverse($handlers);

        foreach ($handlers as $handler) {
            self::register($kind, $handler);
        }

        return $handlers;
    }

    /**
     * Replace whatever the framework left behind with the handlers captured in
     * setUp(), restoring the exact stack PHPUnit is comparing against.
     *
     * @param  list<callable>  $handlers
     */
    private static function restoreHandlers(string $kind, array $handlers): void
    {
        while (self::current($kind) !== null) {
            self::restore($kind);
        }

        foreach ($handlers as $handler) {
            self::register($kind, $handler);
        }
    }

    private static function current(string $kind): ?callable
    {
        $current = $kind === 'error' ? get_error_handler() : get_exception_handler();

        return is_callable($current) ? $current : null;
    }

    private static function register(string $kind, callable $handler): void
    {
        if ($kind === 'error') {
            set_error_handler($handler);

            return;
        }

        set_exception_handler($handler);
    }

    private static function restore(string $kind): void
    {
        if ($kind === 'error') {
            restore_error_handler();

            return;
        }

        restore_exception_handler();
    }
}
