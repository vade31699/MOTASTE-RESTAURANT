<?php

/**
 * Database security 5.1 — prepared statements / no dynamic SQL.
 *
 * Every user-controlled value must reach SQL as a bound parameter, never as an
 * interpolated string, and any SQL identifier built inside a query must come
 * from an allowlist of application-controlled literals. These structural
 * assertions scan the live code paths (public/api + app) and fail on:
 *
 *  1. a `$` interpolated inside any raw SQL string literal
 *     (whereRaw/selectRaw/orderByRaw/groupByRaw/havingRaw/DB::raw);
 *  2. DB::select/DB::statement/DB::unprepared whose call text contains a `$`;
 *  3. a dynamic first argument to orderBy/orderByDesc/groupBy/select;
 *  4. DB::table(<variable>) anywhere outside the audited allowlist — the only
 *     sites that legitimately resolve a table name at runtime, and each one is
 *     backed by hardcoded literals ('staff'/'admins' or fixed truncate lists).
 */
function preparedStatementScanTargets(): array
{
    $targets = [];
    $roots = [
        __DIR__ . '/../../public/api',
        __DIR__ . '/../../app',
    ];
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $targets[] = $file->getPathname();
        }
    }
    return $targets;
}

/** Extract every raw-SQL string literal argument (first quoted arg of the call). */
function preparedStatementRawSqlLiterals(string $src): array
{
    preg_match_all(
        '/\b(?:whereRaw|orWhereRaw|havingRaw|selectRaw|orderByRaw|groupByRaw)\s*\(\s*(["\'])([^"\']*)\1/s',
        $src,
        $m
    );
    $literals = $m[2] ?? [];

    preg_match_all('/\bDB::raw\s*\(\s*(["\'])([^"\']*)\1/s', $src, $rm);
    return array_merge($literals, $rm[2] ?? []);
}

test('no raw SQL string literal in the app interpolates a runtime value', function () {
    $offenders = [];
    foreach (preparedStatementScanTargets() as $path) {
        $src = (string) @file_get_contents($path);
        foreach (preparedStatementRawSqlLiterals($src) as $literal) {
            if (str_contains($literal, '$')) {
                $offenders[] = basename($path) . ': ' . $literal;
            }
        }
    }
    expect($offenders)->toBe([]);
});

test('DB::select/statement/unprepared are never called with interpolated text', function () {
    $offenders = [];
    foreach (preparedStatementScanTargets() as $path) {
        $src = (string) @file_get_contents($path);
        // Remove the one fully-static health probe before scanning.
        $src = str_replace('DB::select(\'select 1 as ok\')', '', $src);
        if (preg_match('/\bDB::(select|statement|unprepared)\s*\([^)]*\$[^)]*\)/', $src) === 1) {
            $offenders[] = $path;
        }
    }
    expect($offenders)->toBe([]);
});

test('orderBy/groupBy/select never take a dynamic first argument', function () {
    // The only dynamic limit() uses are bool-guarded or derived from a COUNT
    // returned by the DB itself — never from request input. Normalize them out
    // before scanning so the probe only flags genuinely dynamic identifiers.
    $allowedLimitSites = [
        'limit($isDateFiltered ? 5000 : 500)',
        'limit($liveCount - STAFF_SESSION_TOKEN_MAX_PER_ACCOUNT + 1)',
    ];

    $offenders = [];
    foreach (preparedStatementScanTargets() as $path) {
        $src = (string) @file_get_contents($path);
        foreach ($allowedLimitSites as $allowed) {
            $src = str_replace($allowed, '', $src);
        }
        if (preg_match('/\->(?:orderBy|orderByDesc|groupBy|groupByRaw|select)\s*\(\s*\$/', $src) === 1) {
            $offenders[] = $path;
        }
    }
    expect($offenders)->toBe([]);
});

test('DB::table(<variable>) only appears in the audited allowlist files', function () {
    $allowlisted = [
        '_staff_auth_helpers.php',
        '_email_auth_helpers.php',
        '_retention_helpers.php',
        'confirm_account_change.php',
        'fresh_start.php',
    ];

    $offenders = [];
    foreach (preparedStatementScanTargets() as $path) {
        $src = (string) @file_get_contents($path);
        // DB::table( not followed by a quote = a variable/expression argument.
        if (preg_match('/\bDB::table\s*\(\s*(?![\'"])/', $src) === 1
            && !in_array(basename($path), $allowlisted, true)) {
            $offenders[] = $path;
        }
    }
    expect($offenders)->toBe([]);

    // Each allowlisted call must reference only 'staff'/'admins' or the fixed
    // truncate list — never the raw request. Spot-check with known probes.
    $helpers = (string) @file_get_contents(__DIR__ . '/../../public/api/_staff_auth_helpers.php');
    expect($helpers)->toContain("'staff'")->toContain("'admins'");
    $freshStart = (string) @file_get_contents(__DIR__ . '/../../public/api/fresh_start.php');
    expect($freshStart)->toContain('$tablesToTruncate');
});