<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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

        parent::setUp();
    }
}