<?php

namespace App\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use JsonSerializable;
use RuntimeException;
use Throwable;

class DbSync extends Command
{
    /** Sentinel sync_state row holding whole-cycle bookkeeping. */
    public const CYCLE_ROW = '__cycle__';

    protected $signature = 'db:sync
        {--tables= : Comma-separated list of tables to mirror (defaults to config/db_sync.php)}
        {--loop : Keep running, one cycle per --interval}
        {--interval= : Seconds between cycles when --loop is used}
        {--cycles= : Stop after this many cycles}
        {--reconcile : Force a full delete reconciliation on every cycle}';

    protected $description = 'Mirror new and updated rows into the backup database and delete rows removed from the source';

    private string $source;

    private string $backup;

    private int $chunk;

    private int $batch;

    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function handle(): int
    {
        $this->source = (string) config('db_sync.source');
        $this->backup = (string) config('db_sync.backup');
        $this->chunk = max(1, (int) config('db_sync.chunk', 500));
        // SQLite refuses more bound parameters than SQLITE_MAX_VARIABLE_NUMBER (999).
        $this->batch = min($this->chunk, 500);

        try {
            $this->prepareBackupConnection();
            $this->prepareStateTables();
        } catch (Throwable $e) {
            Log::error('db:sync failed to start', [
                'source' => $this->source,
                'backup' => $this->backup,
                'message' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tables = $this->tablesToMirror();

        if ($tables === []) {
            $this->error('No tables to mirror. Set db_sync.tables or pass --tables.');

            return self::FAILURE;
        }

        $loop = (bool) $this->option('loop');
        $interval = max(1, (int) ($this->option('interval') ?: config('db_sync.interval', 30)));
        $cycles = $this->option('cycles') !== null ? max(1, (int) $this->option('cycles')) : null;

        if ($loop) {
            $this->line(sprintf(
                'Mirroring %d table(s) every %ds from [%s] into [%s]. Ctrl+C to stop.',
                count($tables),
                $interval,
                $this->source,
                $this->backup,
            ));
        }

        $run = 0;

        while (true) {
            $run++;

            $results = $this->runCycle($tables);

            $changed = collect($results)->filter(fn ($result) => $result['result'] !== 'none');
            $errored = collect($results)->filter(fn ($result) => $result['error'] !== null);

            Log::info('db:sync cycle finished', [
                'run' => $run,
                'changed_tables' => $changed->count(),
                'error_tables' => $errored->count(),
                'tables' => $results,
            ]);

            $this->report($results, $loop);

            if (! $loop || ($cycles !== null && $run >= $cycles)) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    /**
     * Run one full pass over every mirrored table.
     *
     * @param  list<string>  $tables
     * @return array<string, array{upserted: int, deleted: int, result: string, error: string|null}>
     */
    private function runCycle(array $tables): array
    {
        $cycleStart = now();
        $reconcile = (bool) $this->option('reconcile') || $this->reconcileDue($this->lastReconciledAt());

        $results = [];

        foreach ($tables as $table) {
            try {
                $results[$table] = $this->syncTable($table, $cycleStart, $reconcile);
            } catch (Throwable $e) {
                // One broken table must not stop the mirror.
                $results[$table] = ['upserted' => 0, 'deleted' => 0, 'result' => 'error', 'error' => $e->getMessage()];
            }
        }

        $this->recordCycle($cycleStart, $reconcile);

        return $results;
    }

    /**
     * @return array{upserted: int, deleted: int, result: string, error: string|null}
     */
    private function syncTable(string $table, DateTimeInterface $cycleStart, bool $reconcile): array
    {
        $this->ensureMirroredTable($table);

        $state = $this->stateFor($table);

        $copy = $this->copyChanges($table, $state, $cycleStart);

        $deleted = 0;

        // A row count is cheap and catches every ordinary deletion, because the
        // appended rows were already copied above. Regenerate the full key-set
        // diff when the counts disagree or when a reconciliation is due.
        if ($reconcile || $this->countsDiffer($table)) {
            $deleted = $this->deleteRemovedRows($table);
        }

        $result = match (true) {
            $copy['upserted'] > 0 && $deleted > 0 => 'new+deleted',
            $copy['upserted'] > 0 => 'new',
            $deleted > 0 => 'deleted',
            default => 'none',
        };

        $this->recordState($table, $cycleStart, $copy, $deleted, $result);

        return ['upserted' => $copy['upserted'], 'deleted' => $deleted, 'result' => $result, 'error' => null];
    }

    /**
     * Copy appended rows and in-place updates into the backup.
     *
     * @return array{upserted: int, watermark: string|null}
     */
    private function copyChanges(string $table, ?object $state, DateTimeInterface $cycleStart): array
    {
        $lastKey = (int) ($state->last_max_id ?? 0);
        $lastSyncedAt = $state->last_synced_at ?? null;
        $updatedAt = in_array('updated_at', $this->columns($table), true);
        $upserted = 0;
        $observed = null;

        // Appended rows: everything past the highest key already mirrored.
        $cursor = $lastKey;

        while (true) {
            $rows = DB::connection($this->source)->table($table)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($this->chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $upserted += $this->writeRows($table, $rows);
            $observed = $this->latest($observed, $rows);
            $cursor = $rows->last()->id;
        }

        // In-place updates: rows the watermark has not seen a change for yet.
        if ($updatedAt && $lastSyncedAt !== null) {
            $cursor = null;

            while (true) {
                $rows = DB::connection($this->source)->table($table)
                    ->where('id', '<=', $lastKey)
                    ->where('updated_at', '>', $lastSyncedAt)
                    ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
                    ->orderBy('id')
                    ->limit($this->chunk)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $upserted += $this->writeRows($table, $rows);
                $observed = $this->latest($observed, $rows);
                $cursor = $rows->last()->id;
            }
        }

        return [
            'upserted' => $upserted,
            'watermark' => $updatedAt ? $this->advanceWatermark($lastSyncedAt, $observed, $cycleStart) : null,
        ];
    }

    /**
     * Move the watermark forward without skipping activity.
     *
     * The watermark only reaches `now - safety_margin`, so a row written while
     * this cycle was reading is picked up by the next one, and it never moves
     * backwards so an out-of-order `updated_at` cannot reopen old ground.
     */
    private function advanceWatermark(?string $previous, ?Carbon $observed, DateTimeInterface $cycleStart): string
    {
        $margin = max(0, (int) config('db_sync.safety_margin', 2));
        $ceiling = Carbon::instance($cycleStart)->subSeconds($margin);

        $candidate = $observed === null ? $ceiling : $observed->min($ceiling);
        $previousAt = $previous !== null ? Carbon::parse($previous) : null;

        if ($previousAt !== null && $previousAt->greaterThan($candidate)) {
            $candidate = $previousAt;
        }

        return $candidate->format('Y-m-d H:i:s.u');
    }

    /** Highest `updated_at` seen in a batch of rows. */
    private function latest(?Carbon $current, Collection $rows): ?Carbon
    {
        foreach ($rows as $row) {
            $value = $row->updated_at ?? null;

            if ($value === null) {
                continue;
            }

            $at = Carbon::parse($value);

            if ($current === null || $at->greaterThan($current)) {
                $current = $at;
            }
        }

        return $current;
    }

    private function writeRows(string $table, Collection $rows): int
    {
        $updateColumns = array_values(array_diff(array_keys((array) $rows->first()), ['id']));

        foreach ($rows->chunk($this->batch) as $chunk) {
            $values = $chunk->map(fn ($row) => $this->normalizeRow((array) $row))->all();

            if ($updateColumns === []) {
                DB::connection($this->backup)->table($table)->insertOrIgnore($values);

                continue;
            }

            DB::connection($this->backup)->table($table)->upsert($values, ['id'], $updateColumns);
        }

        return $rows->count();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return array_map(fn ($value) => match (true) {
            is_array($value) => json_encode($value),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof JsonSerializable => json_encode($value),
            is_object($value) => json_encode($value),
            default => $value,
        }, $row);
    }

    private function countsDiffer(string $table): bool
    {
        return DB::connection($this->source)->table($table)->count()
            !== DB::connection($this->backup)->table($table)->count();
    }

    /**
     * Delete every mirrored row whose primary key no longer exists in the
     * source.
     *
     * The mirrored keys are the candidate set: a deleted row is gone from the
     * source, so walking the source could never visit it. Keys are read in
     * order and checked against the source in batches, which keeps memory flat
     * on tables of any size.
     */
    private function deleteRemovedRows(string $table): int
    {
        $missing = [];
        $cursor = null;

        while (true) {
            $ids = DB::connection($this->backup)->table($table)
                ->select('id')
                ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
                ->orderBy('id')
                ->limit($this->batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $present = DB::connection($this->source)->table($table)
                ->whereIn('id', $ids->all())
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->flip();

            foreach ($ids as $id) {
                if (! $present->has((string) $id)) {
                    $missing[] = $id;
                }
            }

            $cursor = $ids->last();
        }

        $deleted = 0;

        foreach (array_chunk($missing, $this->batch) as $chunk) {
            $deleted += DB::connection($this->backup)->table($table)->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }

    /**
     * @param  array{upserted: int, watermark: string|null}  $copy
     */
    private function recordState(
        string $table,
        DateTimeInterface $at,
        array $copy,
        int $deleted,
        string $result,
    ): void {
        $connection = DB::connection($this->backup);

        $connection->table('sync_state')->updateOrInsert(
            ['table_name' => $table],
            [
                'last_max_id' => $connection->table($table)->max('id'),
                'last_synced_at' => $copy['watermark'],
                'last_run_at' => $at,
                'rows_upserted' => $copy['upserted'],
                'rows_deleted' => $deleted,
                'last_result' => $result,
                'created_at' => $at,
                'updated_at' => $at,
            ],
        );

        if ($copy['upserted'] > 0 || $deleted > 0) {
            $connection->table('sync_events')->insert([
                'table_name' => $table,
                'new_rows' => $copy['upserted'],
                'deleted_rows' => $deleted,
                'result' => $result,
                'created_at' => $at,
            ]);
        }
    }

    private function recordCycle(DateTimeInterface $at, bool $reconciled): void
    {
        $values = ['last_run_at' => $at, 'created_at' => $at, 'updated_at' => $at];

        if ($reconciled) {
            $values['last_reconciled_at'] = $at;
        }

        DB::connection($this->backup)->table('sync_state')->updateOrInsert(
            ['table_name' => self::CYCLE_ROW],
            $values,
        );
    }

    private function stateFor(string $table): ?object
    {
        return DB::connection($this->backup)->table('sync_state')->where('table_name', $table)->first();
    }

    private function lastReconciledAt(): ?string
    {
        return DB::connection($this->backup)->table('sync_state')
            ->where('table_name', self::CYCLE_ROW)
            ->value('last_reconciled_at');
    }

    private function reconcileDue(?string $lastReconciledAt): bool
    {
        if ($lastReconciledAt === null) {
            return true;
        }

        $every = max(1, (int) config('db_sync.reconcile_every', 10)) * max(1, (int) config('db_sync.interval', 30));

        return now()->diffInSeconds(Carbon::parse($lastReconciledAt)) >= $every;
    }

    /**
     * The mirror is built on a single-column `id` key: it is the watermark for
     * appended rows and the identity used to spot deletions. Every table in
     * this project declares one, and naming it as a literal keeps the generated
     * SQL static, which tests/Unit/PreparedStatementGuardTest.php requires.
     */
    private function assertMirrorable(string $table): void
    {
        $index = collect(Schema::connection($this->source)->getIndexes($table))->firstWhere('primary', true);

        if (($index['columns'] ?? []) !== ['id']) {
            throw new RuntimeException("Table [{$table}] does not have a single-column [id] primary key, so it cannot be mirrored.");
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columnCache[$table] ??= Schema::connection($this->source)->getColumnListing($table);
    }

    /**
     * Create the mirror table from the source schema, and keep it in step when
     * the source gains a column.
     */
    private function ensureMirroredTable(string $table): void
    {
        $source = Schema::connection($this->source);

        if (! $source->hasTable($table)) {
            throw new RuntimeException("Table [{$table}] does not exist on connection [{$this->source}].");
        }

        $columns = $source->getColumns($table);
        $this->assertMirrorable($table);
        $backup = Schema::connection($this->backup);

        if (! $backup->hasTable($table)) {
            $backup->create($table, function (Blueprint $blueprint) use ($columns) {
                $this->defineColumns($blueprint, $columns);
            });

            return;
        }

        $existing = array_map('strtolower', $backup->getColumnListing($table));
        $added = array_filter($columns, fn ($column) => ! in_array(strtolower($column['name']), $existing, true));

        if ($added === []) {
            return;
        }

        $backup->table($table, function (Blueprint $blueprint) use ($added) {
            foreach ($added as $column) {
                // SQLite only accepts a column addition when it is nullable.
                $blueprint->{$this->mapColumnType($column)}($column['name'])->nullable();
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    private function defineColumns(Blueprint $blueprint, array $columns): void
    {
        foreach ($columns as $column) {
            $name = $column['name'];

            if ($name === 'id' && ($column['auto_increment'] ?? false)) {
                $blueprint->id();

                continue;
            }

            $definition = $blueprint->{$this->mapColumnType($column)}($name);

            if ($column['nullable'] ?? false) {
                $definition->nullable();
            }

            if ($name === 'id') {
                $definition->primary();
            }
        }
    }

    /** Map a source column onto the closest portable schema type. */
    private function mapColumnType(array $column): string
    {
        $type = strtolower(strtok((string) ($column['type'] ?? ''), '(') ?: '');

        if ($type === '') {
            $type = strtolower((string) ($column['type_name'] ?? 'text'));
        }

        return match (true) {
            str_contains($type, 'interval') => 'string',
            str_contains($type, 'bool') => 'boolean',
            str_contains($type, 'bigint'), str_contains($type, 'int8'), str_contains($type, 'serial8') => 'bigInteger',
            str_contains($type, 'smallint'), str_contains($type, 'int2'), str_contains($type, 'integer'),
            str_contains($type, 'serial'), (bool) preg_match('/\b(int|int4|tinyint|mediumint)\b/', $type) => 'integer',
            str_contains($type, 'double'), str_contains($type, 'real'), str_contains($type, 'float') => 'float',
            str_contains($type, 'numeric'), str_contains($type, 'decimal'), str_contains($type, 'money') => 'decimal',
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => 'timestamp',
            $type === 'date' => 'date',
            str_contains($type, 'time') => 'time',
            str_contains($type, 'json'), str_contains($type, 'uuid'), str_contains($type, 'text'),
            str_contains($type, 'clob'), str_contains($type, 'xml'), str_contains($type, 'enum') => 'text',
            str_contains($type, 'bytea'), str_contains($type, 'blob'), str_contains($type, 'binary') => 'binary',
            str_contains($type, 'char'), str_contains($type, 'string'), str_contains($type, 'varying') => 'string',
            default => 'text',
        };
    }

    /** @return list<string> */
    private function tablesToMirror(): array
    {
        $option = (string) $this->option('tables');

        $tables = $option !== ''
            ? explode(',', $option)
            : (array) config('db_sync.tables', []);

        return array_values(array_filter(array_map('trim', $tables)));
    }

    /** Make sure the backup connection is configured and can be connected to. */
    private function prepareBackupConnection(): void
    {
        $config = config("database.connections.{$this->backup}");

        if ($config === null) {
            throw new RuntimeException("Connection [{$this->backup}] is not configured in config/database.php.");
        }

        DB::purge($this->backup);
    }

    private function prepareStateTables(): void
    {
        $schema = Schema::connection($this->backup);

        if (! $schema->hasTable('sync_state')) {
            $schema->create('sync_state', function (Blueprint $table) {
                $table->string('table_name')->primary();
                $table->bigInteger('last_max_id')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('last_reconciled_at')->nullable();
                $table->integer('rows_upserted')->default(0);
                $table->integer('rows_deleted')->default(0);
                $table->string('last_result')->default('none');
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('sync_events')) {
            $schema->create('sync_events', function (Blueprint $table) {
                $table->id();
                $table->string('table_name');
                $table->integer('new_rows')->default(0);
                $table->integer('deleted_rows')->default(0);
                $table->string('result');
                $table->timestamp('created_at')->nullable();
                $table->index(['table_name', 'created_at']);
            });
        }
    }

    /**
     * @param  array<string, array{upserted: int, deleted: int, result: string, error: string|null}>  $results
     */
    private function report(array $results, bool $compact): void
    {
        $errors = collect($results)->filter(fn ($result) => $result['error'] !== null);
        $changed = collect($results)->filter(fn ($result) => $result['result'] !== 'none');

        if ($compact) {
            $this->line(sprintf(
                '[%s] %s - %d of %d table(s) changed%s',
                now()->toDateTimeString(),
                $changed->isEmpty() ? 'none' : $changed->pluck('result')->unique()->implode(', '),
                $changed->count(),
                count($results),
                $errors->isEmpty() ? '' : sprintf(', %d error(s)', $errors->count()),
            ));
        } else {
            $this->table(
                ['Table', 'New/Updated', 'Deleted', 'Result'],
                collect($results)->map(fn ($result, $table) => [
                    $table,
                    $result['upserted'],
                    $result['deleted'],
                    $result['result'],
                ])->values()->all(),
            );
        }

        foreach ($errors as $table => $result) {
            $this->error("{$table}: {$result['error']}");
        }
    }
}
