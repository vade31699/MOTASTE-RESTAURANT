<?php

/**
 * Restore a database from the off-site mirror (Supabase) without the Postgres
 * command-line tools.
 *
 * `php artisan migrate --force` builds the schema on the target; this script
 * copies the mirrored rows into it and then checks that both sides agree. It is
 * the psql/pg_dump-free equivalent of the restore runbook in README.md, for
 * machines that have PHP and this repository but no Postgres client installed.
 *
 * What it does:
 *  1. Reads the mirrored table list from the mirror's own `sync_state`, so the
 *     list can never drift from what the mirror actually carries.
 *  2. Orders the tables so a parent row is written before anything referencing
 *     it (`staff` and `admins` both point at `users`).
 *  3. Walks each table by id in chunks and upserts every row into the target,
 *     then resets the target's sequences so the next insert cannot collide with
 *     an imported id.
 *  4. Verifies: per-table row count + min/max id + an md5 of the ids, foreign
 *     key orphans, and sequence state. Ids are the one column that is typed
 *     identically on both sides, which is why the digest is built from them.
 *
 * Safety:
 *  - Rows are inserted or updated by id and **never deleted**, and nothing
 *     outside the mirrored tables is touched. `sync_state`, `sync_events` and
 *     the tables the mirror deliberately excludes (sessions, credentials, cache,
 *     jobs) are left alone.
 *  - `--apply` refuses to run when the target is the mirror itself, and refuses
 *     to write into a target that already holds rows in the mirrored tables
 *     unless `--force` is passed — a mistyped URL cannot quietly merge into a
 *     live database.
 *  - Without `--apply` nothing is written; the default is a report.
 *
 * Usage:
 *   php scripts/restore_from_mirror.php --help
 *   php scripts/restore_from_mirror.php --target-url="postgresql://user:pass@host:5432/db"   # report only
 *   php scripts/restore_from_mirror.php --target-url="..." --apply                           # import + verify
 *   php scripts/restore_from_mirror.php --target-url="..." --verify                          # verify an existing restore
 *   php scripts/restore_from_mirror.php --target-url="..." --apply --tables=users,orders --chunk=1000
 *
 * Connection options: --target-url, or the discrete --target-host / --target-port
 * / --target-database / --target-username / --target-password / --target-sslmode.
 * Set the password as TARGET_DB_PASSWORD instead to keep it out of shell history.
 * The mirror defaults to the `db_sync.backup` connection (`supabase`); --mirror or
 * --mirror-url override it.
 *
 * Both connections must be PostgreSQL: the mirror is, and the verification uses
 * Postgres functions (`string_agg`, `md5`, `pg_get_serial_sequence`).
 *
 * Exit codes: 0 = both sides agree, 2 = verification found differences, 1 = error.
 */

use App\Console\Commands\DbSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const RESTORE_TARGET = 'restore_target';
const RESTORE_MIRROR_URL = 'restore_mirror_url';
const RESTORE_DEFAULT_CHUNK = 500;
/** Postgres rejects more than 65535 bound parameters in one statement. */
const RESTORE_MAX_PARAMETERS = 60000;
/** How many missing ids to print per table before summarising. */
const RESTORE_MAX_LISTED_IDS = 20;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ---------------------------------------------------------------------- helpers

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");

    exit(1);
}

function printUsage(): void
{
    echo <<<'TEXT'
Restore the mirrored tables from the off-site mirror into a PostgreSQL database.

  php scripts/restore_from_mirror.php --target-url=<url> [options]

  --target-url=<url>        postgresql://user:pass@host:5432/database (target)
  --target-host=<host>      …or connect with discrete options instead of a URL
  --target-port=<port>      default 5432
  --target-database=<name>
  --target-username=<user>
  --target-password=<pass>  prefer the TARGET_DB_PASSWORD environment variable
  --target-sslmode=<mode>   default require
  --mirror=<connection>     Laravel connection to read from (default db_sync.backup)
  --mirror-url=<url>        …or an ad-hoc URL for the mirror
  --tables=a,b,c            limit the run to these mirrored tables
  --chunk=<rows>            rows per batch (default 500)

  --apply                   write rows and reset sequences (default: report only)
  --force                   allow --apply against a target that already has rows
  --verify                  verify only; never write
  --help                    show this message

Run `php artisan migrate --force` against the target first: the script imports
data, it does not create the schema. Exit codes: 0 agree, 2 differences, 1 error.

TEXT;
}

function assertSafeTableName(string $table): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        fail("Refusing to work with unexpected table name [{$table}].");
    }

    return $table;
}

function quoteIdentifier(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function quoteQualified(string $name): string
{
    return implode('.', array_map('quoteIdentifier', explode('.', $name)));
}

/**
 * Parse a postgresql:// URL into connection values. Percent-encode anything
 * special in the password (an @ in a password has to be %40).
 *
 * @return array<string, ?string>
 */
function parsePostgresUrl(string $role, string $url): array
{
    $parsed = parse_url($url);

    if ($parsed === false || ($parsed['host'] ?? null) === null) {
        fail(
            "Could not parse the {$role} URL. Percent-encode special characters in the password (for example @ as %40), " .
            'or use the discrete --' . $role . '-host options instead.'
        );
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? 'postgresql'));

    if (! in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true)) {
        fail("The {$role} URL must be a postgresql:// URL, got [{$scheme}://].");
    }

    parse_str((string) ($parsed['query'] ?? ''), $query);

    return [
        'host' => (string) $parsed['host'],
        'port' => (string) ($parsed['port'] ?? $query['port'] ?? '5432'),
        'database' => ltrim((string) ($parsed['path'] ?? ''), '/'),
        'username' => isset($parsed['user']) ? urldecode((string) $parsed['user']) : null,
        'password' => isset($parsed['pass']) ? urldecode((string) $parsed['pass']) : null,
        'sslmode' => isset($query['sslmode']) ? (string) $query['sslmode'] : 'require',
    ];
}

/** @return array<string, mixed> */
function postgresConfiguration(string $role, string $prefix, ?string $url, array $values): array
{
    $fromUrl = $url !== null ? parsePostgresUrl($role, $url) : [];

    $host = $values["{$prefix}-host"] ?? $fromUrl['host'] ?? null;
    $database = $values["{$prefix}-database"] ?? $fromUrl['database'] ?? null;
    $username = $values["{$prefix}-username"] ?? $fromUrl['username'] ?? null;
    $password = $values["{$prefix}-password"] ?? $fromUrl['password'] ?? null;

    if ($prefix === 'target') {
        $password ??= (getenv('TARGET_DB_PASSWORD') ?: null);
    }

    if ($host === null || $database === null || $database === '') {
        fail(
            "The {$role} is not configured. Pass --{$prefix}-url, or the discrete --{$prefix}-host and " .
            "--{$prefix}-database options (see --help)."
        );
    }

    if ($username === null || $password === null || $password === '') {
        fail(
            "The {$role} needs a username and password: pass them in the URL, as --{$prefix}-username / " .
            "--{$prefix}-password, or set TARGET_DB_PASSWORD for the target."
        );
    }

    return [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => (string) ($values["{$prefix}-port"] ?? $fromUrl['port'] ?? '5432'),
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => (string) ($values["{$prefix}-sslmode"] ?? $fromUrl['sslmode'] ?? 'require'),
    ];
}

function connect(string $name, string $role, array $configuration): ConnectionInterface
{
    config(["database.connections.{$name}" => $configuration]);
    DB::purge($name);

    try {
        $connection = DB::connection($name);
        $connection->select('select 1');
    } catch (Throwable $e) {
        fail("Cannot connect to the {$role}: " . $e->getMessage());
    }

    return $connection;
}

function describeConnection(ConnectionInterface $connection): string
{
    $configuration = $connection->getConfig();

    return sprintf(
        '%s://%s@%s:%s/%s',
        $connection->getDriverName(),
        $configuration['username'] ?? '?',
        $configuration['host'] ?? '?',
        $configuration['port'] ?? '?',
        $connection->getDatabaseName(),
    );
}

/**
 * Columns to write: what both sides have, in the mirror's order. A column the
 * target does not have (a dropped or renamed migration) is skipped, and a target
 * column the mirror never carried keeps its default.
 *
 * @return list<string>
 */
function writableColumns(string $mirrorName, string $targetName, string $table): array
{
    $mirrorColumns = Schema::connection($mirrorName)->getColumnListing($table);
    $targetColumns = array_map('strtolower', Schema::connection($targetName)->getColumnListing($table));

    $columns = array_values(array_filter(
        $mirrorColumns,
        static fn (string $column) => in_array(strtolower($column), $targetColumns, true),
    ));

    if (! in_array('id', array_map('strtolower', $columns), true)) {
        fail("Table [{$table}] has no id column on both sides, so it cannot be restored by id.");
    }

    return $columns;
}

/**
 * Split the table list so no table is written before something it references.
 * `staff.user_id` and `admins.user_id` both point at `users`, while the mirror's
 * own table order lists `admins` first.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function orderForForeignKeys(ConnectionInterface $target, array $tables): array
{
    $dependencies = array_fill_keys($tables, []);

    $references = $target->select(
        "select tc.table_name as child, ccu.table_name as parent
           from information_schema.table_constraints tc
           join information_schema.constraint_column_usage ccu
             on ccu.constraint_name = tc.constraint_name
            and ccu.constraint_schema = tc.constraint_schema
          where tc.constraint_type = 'FOREIGN KEY'
            and tc.table_schema = current_schema()"
    );

    foreach ($references as $reference) {
        if (isset($dependencies[$reference->child], $dependencies[$reference->parent]) && $reference->child !== $reference->parent) {
            $dependencies[$reference->child][] = $reference->parent;
        }
    }

    $ordered = [];
    $remaining = $dependencies;

    while ($remaining !== []) {
        $progressed = false;

        foreach ($remaining as $table => $parents) {
            if (array_diff(array_unique($parents), $ordered) === []) {
                $ordered[] = $table;
                unset($remaining[$table]);
                $progressed = true;
            }
        }

        if (! $progressed) {
            // A cycle of references: keep the rest in the order the mirror listed
            // them rather than looping forever.
            return array_merge($ordered, array_keys($remaining));
        }
    }

    return $ordered;
}

/** Rows per statement, capped so a wide table cannot exceed the parameter limit. */
function batchSize(array $columns, int $chunk): int
{
    return max(1, min($chunk, intdiv(RESTORE_MAX_PARAMETERS, max(1, count($columns)))));
}

/**
 * Upsert every mirrored row into the target, walking by id so memory stays flat
 * on tables of any size. Rows are never deleted, so re-running is safe.
 */
function importTable(
    ConnectionInterface $mirror,
    ConnectionInterface $target,
    string $table,
    array $columns,
    int $chunk,
): int {
    $batch = batchSize($columns, $chunk);
    $update = array_values(array_diff($columns, ['id']));
    $cursor = 0;
    $written = 0;

    while (true) {
        $rows = $mirror->table($table)
            ->select($columns)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        if ($rows->isEmpty()) {
            break;
        }

        // Keyed by column name, and every row in the same order: the insert
        // grammar takes the column list from the first row's keys, so an
        // array_map over the list would instead write to columns named 0, 1, 2…
        $values = $rows->map(static function ($row) use ($columns) {
            $values = [];

            foreach ($columns as $column) {
                $values[$column] = $row->{$column} ?? null;
            }

            return $values;
        })->all();

        $writes = $target->table($table);

        // A table keyed only by id has nothing to update on conflict.
        $update === []
            ? $writes->insertOrIgnore($values)
            : $writes->upsert($values, ['id'], $update);

        $written += $rows->count();
        $cursor = (int) $rows->last()->id;
    }

    return $written;
}

/**
 * Ids the mirror has and the target does not.
 *
 * The mirror is the candidate set: a row that never made it into the target is
 * absent from the target, so walking the target could never visit it. Ids are
 * read in order and checked against the target in batches, which keeps memory
 * flat. After an import the rows still missing are the ones written while the
 * pass was running.
 *
 * @return array{count: int, sample: list<int|string>}
 */
function missingIds(
    ConnectionInterface $mirror,
    ConnectionInterface $target,
    string $table,
    int $chunk,
): array {
    $count = 0;
    $sample = [];
    $cursor = 0;

    while (true) {
        $ids = $mirror->table($table)
            ->select('id')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($chunk)
            ->pluck('id');

        if ($ids->isEmpty()) {
            break;
        }

        $present = $target->table($table)
            ->whereIn('id', $ids->all())
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->flip();

        foreach ($ids as $id) {
            if (! $present->has((string) $id)) {
                $count++;

                if (count($sample) < RESTORE_MAX_LISTED_IDS) {
                    $sample[] = $id;
                }
            }
        }

        $cursor = $ids->last();
    }

    return ['count' => $count, 'sample' => $sample];
}

/**
 * Row count plus a digest of the ids, computed identically on both sides.
 *
 * Ids are the only column typed identically on both sides (the mirror maps
 * source types onto a portable subset), which is what makes them safe to
 * compare. The digest catches a table with the right number of rows and the
 * wrong ones.
 */
function idDigest(ConnectionInterface $connection, string $table): string
{
    $digest = $connection->selectOne(sprintf(
        "select count(*) || ':' || coalesce(min(id)::text, '-') || ':' || coalesce(max(id)::text, '-') || ':' " .
        "|| coalesce(md5(string_agg(id::text, ',' order by id)), 'empty') as digest from %s",
        quoteQualified($table),
    ));

    return (string) $digest->digest;
}

/**
 * Foreign keys the target enforces whose child rows point at a missing parent.
 * A clean restore has none: it means parent rows landed before their children.
 *
 * @param  list<string>  $tables
 * @return list<array<string, mixed>>
 */
function orphanedReferences(ConnectionInterface $target, array $tables): array
{
    $references = $target->select(
        "select tc.table_name as child_table, kcu.column_name as child_column,
                ccu.table_name as parent_table, ccu.column_name as parent_column
           from information_schema.table_constraints tc
           join information_schema.key_column_usage kcu
             on kcu.constraint_name = tc.constraint_name
            and kcu.constraint_schema = tc.constraint_schema
           join information_schema.constraint_column_usage ccu
             on ccu.constraint_name = tc.constraint_name
            and ccu.constraint_schema = tc.constraint_schema
          where tc.constraint_type = 'FOREIGN KEY'
            and tc.table_schema = current_schema()"
    );

    $orphans = [];

    foreach ($references as $reference) {
        if (! in_array($reference->child_table, $tables, true)) {
            continue;
        }

        $count = (int) $target->selectOne(sprintf(
            'select count(*) as total from %s c where c.%s is not null and not exists (select 1 from %s p where p.%s = c.%s)',
            quoteQualified($reference->child_table),
            quoteIdentifier($reference->child_column),
            quoteQualified($reference->parent_table),
            quoteIdentifier($reference->parent_column),
            quoteIdentifier($reference->child_column),
        ))->total;

        if ($count > 0) {
            $orphans[] = [
                'table' => $reference->child_table,
                'column' => $reference->child_column,
                'parent_table' => $reference->parent_table,
                'parent_column' => $reference->parent_column,
                'count' => $count,
            ];
        }
    }

    return $orphans;
}

/**
 * Sequences that have not caught up with the data, because the ids came from
 * the mirror rather than from the target's own sequences: a sequence still at 1
 * would hand out ids that are already taken.
 *
 * This is deliberately conservative — it flags a sequence whose next value is at
 * or below the highest id in the table, whether or not that exact id is free —
 * because the fix (`resetSequences`) is idempotent and cheap.
 *
 * @param  list<string>  $tables
 * @return list<array<string, mixed>>
 */
function staleSequences(ConnectionInterface $target, array $tables): array
{
    $stale = [];

    foreach (array_values($tables) as $table) {
        $max = $target->table($table)->max('id');

        if ($max === null) {
            continue;   // empty table: nothing to collide with
        }

        $sequence = $target->selectOne('select pg_get_serial_sequence(?, ?) as sequence', ["public.{$table}", 'id'])->sequence;

        if ($sequence === null) {
            continue;   // not sequence-backed (a view, or keyed another way)
        }

        $state = $target->selectOne('select last_value, is_called from ' . quoteQualified($sequence));
        $called = in_array($state->is_called, [true, 't', 'true', '1', 1], true);
        $next = (int) $state->last_value + ($called ? 1 : 0);

        if ($next <= (int) $max) {
            $stale[] = ['table' => $table, 'sequence' => $sequence, 'next' => $next, 'max' => (int) $max];
        }
    }

    return $stale;
}

/**
 * Pull every sequence up to the highest id in its table, so the next insert in
 * the application continues past the restored rows instead of colliding. Only
 * applies to sequence-backed ids (`pg_get_serial_sequence` returns null for
 * everything else), and empty tables are left alone.
 */
function resetSequences(ConnectionInterface $target): void
{
    $target->statement(<<<'SQL'
        DO $$
        DECLARE
            r record;
            seq text;
            max_id bigint;
        BEGIN
            FOR r IN
                SELECT table_name FROM information_schema.columns
                WHERE table_schema = 'public' AND column_name = 'id'
                ORDER BY table_name
            LOOP
                seq := pg_get_serial_sequence(format('public.%I', r.table_name), 'id');
                IF seq IS NULL THEN
                    CONTINUE;
                END IF;
                EXECUTE format('SELECT COALESCE(max(id), 0) FROM public.%I', r.table_name) INTO max_id;
                IF max_id > 0 THEN
                    PERFORM setval(seq, max_id);
                END IF;
            END LOOP;
        END $$;
        SQL);
}

/**
 * Compare the two sides: digests per table, dangling references, and whether the
 * sequences are caught up.
 *
 * @param  list<string>  $tables
 * @return array{tables: array<string, array{mirror: string, target: string}>, orphans: list<array<string, mixed>>, sequences: list<array<string, mixed>>}
 */
function verify(ConnectionInterface $mirror, ConnectionInterface $target, array $tables): array
{
    $differing = [];

    foreach (array_values($tables) as $table) {
        $mirrorDigest = idDigest($mirror, $table);
        $targetDigest = idDigest($target, $table);

        if ($mirrorDigest !== $targetDigest) {
            $differing[$table] = ['mirror' => $mirrorDigest, 'target' => $targetDigest];
        }
    }

    return [
        'tables' => $differing,
        'orphans' => orphanedReferences($target, $tables),
        'sequences' => staleSequences($target, $tables),
    ];
}

// ------------------------------------------------------------------- arguments

$flags = ['apply' => false, 'verify' => false, 'force' => false, 'help' => false];
$options = [];
$knownOptions = [
    'target-url', 'target-host', 'target-port', 'target-database', 'target-username',
    'target-password', 'target-sslmode', 'mirror', 'mirror-url', 'tables', 'chunk',
];

foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['-h', '--help'], true)) {
        $flags['help'] = true;

        continue;
    }

    if (! str_starts_with($argument, '--')) {
        fail("Unrecognised argument [{$argument}]. Run with --help.");
    }

    [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

    if ($value === null && array_key_exists($name, $flags)) {
        $flags[$name] = true;

        continue;
    }

    if (! in_array($name, $knownOptions, true)) {
        fail("Unrecognised option [--{$name}]. Run with --help.");
    }

    if ($value === null || $value === '') {
        fail("Option [--{$name}] needs a value, for example --{$name}=value.");
    }

    $options[$name] = $value;
}

if ($flags['help']) {
    printUsage();

    exit(0);
}

if ($flags['apply'] && $flags['verify']) {
    fail('Use either --apply or --verify, not both: --apply imports and then verifies, --verify never writes.');
}

$apply = $flags['apply'];
$verifyOnly = $flags['verify'];

if ($flags['force'] && ! $apply) {
    fail('--force only means something together with --apply.');
}

$chunk = max(1, (int) ($options['chunk'] ?? config('db_sync.chunk', RESTORE_DEFAULT_CHUNK)));

// ----------------------------------------------------------------- connections

if (($options['mirror-url'] ?? null) !== null) {
    $mirror = connect(
        RESTORE_MIRROR_URL,
        'mirror (--mirror-url)',
        postgresConfiguration('mirror', 'mirror', (string) $options['mirror-url'], $options),
    );
} else {
    $mirrorName = (string) ($options['mirror'] ?? config('db_sync.backup', 'backup'));
    $mirrorConfiguration = config("database.connections.{$mirrorName}");

    if (! is_array($mirrorConfiguration)) {
        fail("Mirror connection [{$mirrorName}] is not configured. Pass --mirror=<connection> or --mirror-url=<url>.");
    }

    // The named connection already carries its credentials from the environment.
    $mirror = connect($mirrorName, "mirror [{$mirrorName}]", $mirrorConfiguration);
}

$target = connect(RESTORE_TARGET, 'target', postgresConfiguration('target', 'target', $options['target-url'] ?? null, $options));

foreach (['mirror' => $mirror, 'target' => $target] as $role => $connection) {
    if ($connection->getDriverName() !== 'pgsql') {
        fail(sprintf(
            'The %s connection must be PostgreSQL (got [%s]); this script relies on Postgres upserts, sequences and verification.',
            $role,
            $connection->getDriverName(),
        ));
    }
}

// -------------------------------------------------------------------- preflight

if (! Schema::connection($mirror->getName())->hasTable('sync_state')) {
    fail("The mirror [{$mirror->getName()}] has no sync_state table — run `php artisan db:sync` first.");
}

$mirrored = $mirror->table('sync_state')
    ->where('table_name', '!=', DbSync::CYCLE_ROW)
    ->orderBy('table_name')
    ->pluck('table_name')
    ->all();

if ($mirrored === []) {
    fail("The mirror [{$mirror->getName()}] has not synced any table yet — run `php artisan db:sync` first.");
}

if (($options['tables'] ?? null) !== null) {
    $requested = array_values(array_filter(array_map('trim', explode(',', (string) $options['tables']))));
    $unknown = array_diff($requested, $mirrored);

    if ($unknown !== []) {
        fail(sprintf('Not mirrored: %s. The mirror carries: %s.', implode(', ', $unknown), implode(', ', $mirrored)));
    }

    $tables = $requested;
} else {
    $tables = $mirrored;
}

$tables = orderForForeignKeys($target, $tables);

$unavailable = array_values(array_filter(
    $tables,
    fn (string $table) => ! Schema::connection($target->getName())->hasTable($table),
));

if ($unavailable !== []) {
    fail(sprintf(
        "The target is missing %d table(s): %s.\n" .
        'Run `php artisan migrate --force` against the target first — this script imports data, it does not create the schema.',
        count($unavailable),
        implode(', ', $unavailable),
    ));
}

$sameDatabase = ($mirror->getConfig()['host'] ?? null) == ($target->getConfig()['host'] ?? null)
    && (string) ($mirror->getConfig()['port'] ?? '') === (string) ($target->getConfig()['port'] ?? '')
    && $mirror->getDatabaseName() === $target->getDatabaseName();

if ($sameDatabase) {
    if ($apply) {
        fail('The target and the mirror are the same database. Point --target-url at the database you want to restore into.');
    }

    echo "Warning: the target and the mirror are the same database, so the comparison below checks the mirror against itself.\n\n";
}

if ($apply && ! $flags['force']) {
    $populated = array_values(array_filter($tables, fn (string $table) => $target->table($table)->exists()));

    if ($populated !== []) {
        fail(sprintf(
            "The target already holds rows in %d mirrored table(s): %s.\n" .
            'Re-run with --force to merge the mirror into it. Rows are updated by id and never deleted, so nothing already there is lost — ' .
            'but check that the target really is the database you mean.',
            count($populated),
            implode(', ', array_slice($populated, 0, 8)) . (count($populated) > 8 ? ', …' : ''),
        ));
    }
}

// ------------------------------------------------------------------ the import

printf("Mirror: %s\n", describeConnection($mirror));
printf("Target: %s\n", describeConnection($target));
printf(
    "Mode:   %s\n",
    match (true) {
        $verifyOnly => 'VERIFY (verification only, never writes)',
        $apply => 'APPLY (imports rows, then verifies)',
        default => 'REPORT (no writes; add --apply to import)',
    },
);
printf("Tables: %d, %d row(s) per batch\n\n", count($tables), $chunk);

$written = 0;
$errors = [];
$missing = [];

foreach ($tables as $table) {
    assertSafeTableName($table);

    $columns = writableColumns($mirror->getName(), $target->getName(), $table);

    try {
        $mirrorRows = $mirror->table($table)->count();
        $targetRows = $target->table($table)->count();
    } catch (Throwable $e) {
        $errors[$table] = $e->getMessage();

        continue;
    }

    if ($apply) {
        try {
            $written += importTable($mirror, $target, $table, $columns, $chunk);
            $targetRows = $target->table($table)->count();
        } catch (Throwable $e) {
            $errors[$table] = 'import failed: ' . $e->getMessage();

            continue;
        }
    }

    // Only worth listing for tables that still differ; the walk is the same
    // either way, so it doubles as the "what would --apply insert" answer.
    $difference = missingIds($mirror, $target, $table, $chunk);

    if ($difference['count'] > 0) {
        $missing[] = ['table' => $table] + $difference;
    }

    printf(
        "  %-24s mirror %6d  target %6d  %s\n",
        $table,
        $mirrorRows,
        $targetRows,
        $difference['count'] === 0 ? 'ok' : sprintf('%d row(s) missing from the target', $difference['count']),
    );
}

// ----------------------------------------------------------------- verification

if ($apply && $errors === []) {
    echo "\nResetting the target's sequences so the next insert continues past the imported ids…\n";

    try {
        resetSequences($target);
    } catch (Throwable $e) {
        fail('Could not reset the target sequences: ' . $e->getMessage());
    }
}

if ($apply && $errors !== []) {
    echo "\nSkipping the sequence reset until every table imports without error.\n";
}

printf(
    "\n%s:\n",
    match (true) {
        $verifyOnly => 'Verification',
        $apply => sprintf('After importing %d row(s), verification', $written),
        default => 'Comparison',
    },
);

$verify = verify($mirror, $target, $tables);

printf(
    "  row counts and id digests   %s\n",
    $verify['tables'] === []
        ? sprintf('all %d table(s) agree', count($tables))
        : sprintf('%d of %d table(s) differ', count($verify['tables']), count($tables)),
);
printf(
    "  dangling references         %s\n",
    $verify['orphans'] === [] ? 'none' : sprintf('%d foreign key(s) with orphan rows', count($verify['orphans'])),
);
printf(
    "  sequences below the data    %s\n",
    $verify['sequences'] === []
        ? 'none'
        : sprintf('%d sequence(s) not caught up with the imported ids', count($verify['sequences'])),
);

if ($missing !== []) {
    echo "\nRows the mirror has and the target does not:\n";

    foreach ($missing as $row) {
        printf(
            "  %-24s %d%s\n",
            $row['table'],
            $row['count'],
            $row['sample'] === []
                ? ''
                : ' — ' . implode(', ', array_slice($row['sample'], 0, RESTORE_MAX_LISTED_IDS))
                    . ($row['count'] > count($row['sample']) ? ', …' : ''),
        );
    }
}

if ($verify['tables'] !== []) {
    echo "\nTables whose digest differs:\n";

    foreach ($verify['tables'] as $table => $digests) {
        printf("  %s\n    mirror  %s\n    target  %s\n", $table, $digests['mirror'], $digests['target']);
    }
}

if ($verify['orphans'] !== []) {
    echo "\nDangling references (the target enforces these, so parents must import first):\n";

    foreach ($verify['orphans'] as $orphan) {
        printf(
            "  %-24s %s -> %s.%s: %d orphan row(s)\n",
            $orphan['table'],
            $orphan['column'],
            $orphan['parent_table'],
            $orphan['parent_column'],
            $orphan['count'],
        );
    }
}

if ($verify['sequences'] !== []) {
    echo "\nSequences below the highest id in their table (a new insert can reuse an id that is already taken):\n";

    foreach ($verify['sequences'] as $sequence) {
        printf("  %-24s next id %d, highest id %d\n", $sequence['table'], $sequence['next'], $sequence['max']);
    }
}

if ($errors !== []) {
    echo "\nErrors:\n";

    foreach ($errors as $table => $message) {
        printf("  %-24s %s\n", $table, $message);
    }
}

echo "\n";

if ($errors !== []) {
    echo "Finished with errors. Nothing was deleted — fix the tables above and re-run.\n";

    exit(1);
}

$differences = count($verify['tables']) + count($verify['orphans']) + count($verify['sequences']);

if ($differences > 0) {
    echo $apply
        ? "The target does not match the mirror yet — rows may have changed while this ran. Re-run to converge.\n"
        : "The target does not match the mirror. Re-run with --apply to import the missing rows.\n";

    exit(2);
}

echo $apply
    ? "Restore complete: every mirrored table agrees with the target.\n"
    : "Every mirrored table already agrees with the target; nothing to import.\n";

exit(0);
