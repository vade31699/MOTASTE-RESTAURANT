<?php

/**
 * Database least privilege (security requirement 5.2).
 *
 * Structural assertions that pin the least-privilege contract in place:
 *  - database connection config never falls back to a root account;
 *  - a dedicated app role exists in the grants script with DML-only rights;
 *  - a separate migration-owner role handles DDL during deploys;
 *  - development and production credentials are documented as separate.
 */

const LEAST_PRIVILEGE_SQL = 'database/security/least_privilege.sql';
const DATABASE_CONFIG = 'config/database.php';
const ENV_EXAMPLE = '.env.example';

test('database config never defaults to a root/superuser account', function () {
    $src = (string) @file_get_contents(__DIR__ . '/../../' . DATABASE_CONFIG);
    expect($src)->not->toBe('');

    // Every connection must use env('DB_USERNAME', '') so a missing variable
    // fails loudly instead of silently connecting as root.
    preg_match_all("/'username'\s*=>\s*env\('DB_USERNAME',\s*'([^']*)'\)/", $src, $m);
    $defaults = $m[1] ?? [];

    expect($defaults)->not->toBeEmpty('no DB username configurations found');
    foreach ($defaults as $default) {
        expect($default)->toBe('', 'a connection still defaults to "' . $default . '"');
    }
    expect($src)->not->toContain("env('DB_USERNAME', 'root')");
});

test('least-privilege grants script creates a dedicated non-superuser app role', function () {
    $path = __DIR__ . '/../../' . LEAST_PRIVILEGE_SQL;
    expect(file_exists($path))->toBeTrue('missing ' . LEAST_PRIVILEGE_SQL);
    $sql = (string) @file_get_contents($path);
    expect($sql)->not->toBe('');

    // A dedicated app role, explicitly WITHOUT superuser/createdb/createrole.
    expect($sql)->toContain("CREATE ROLE motaste_app");
    expect($sql)->toContain('NOSUPERUSER NOCREATEDB NOCREATEROLE');
    expect($sql)->toContain('ALTER ROLE motaste_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT');

    // Only the DML privileges the web app requires at runtime.
    expect($sql)->toContain('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES');
    expect($sql)->toContain('GRANT USAGE, SELECT ON ALL SEQUENCES');

    // The app role must never be granted DDL/ownership of tables.
    expect($sql)->not->toContain('GRANT DROP ON ALL TABLES');
    expect($sql)->not->toContain('GRANT TRUNCATE ON ALL TABLES');
});

test('migrations run with a dedicated owner role, not the app role', function () {
    $sql = (string) @file_get_contents(__DIR__ . '/../../' . LEAST_PRIVILEGE_SQL);

    expect($sql)->toContain("CREATE ROLE motaste_migrator");
    expect($sql)->toContain('motaste_migrator');
    expect($sql)->toContain('GRANT ALL ON SCHEMA public TO motaste_migrator');
    expect($sql)->toContain('ALTER DEFAULT PRIVILEGES FOR ROLE motaste_migrator');
    expect($sql)->toContain('DB_USERNAME=motaste_migrator php artisan migrate --force');
});

test('development and production credentials are documented as separate', function () {
    $env = (string) @file_get_contents(__DIR__ . '/../../' . ENV_EXAMPLE);
    expect($env)->not->toBe('');

    // Dev uses SQLite (no credentials); production uses a dedicated pgsql role.
    expect($env)->toContain('DB_CONNECTION=sqlite');

    // Doc block separates dev vs prod and mandates dedicated accounts.
    $lower = strtolower($env);
    expect($lower)->toContain('least privilege');
    expect($lower)->toContain('never root');
    expect($lower)->toContain('motaste_app');
    expect($lower)->toContain('separate .env files');

    // The example must never show root credentials as a valid default.
    expect($env)->not->toContain('DB_USERNAME=root');
    expect($env)->not->toContain('DB_PASSWORD=root');
});