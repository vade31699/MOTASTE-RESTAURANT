<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The mirror is exercised against two throwaway SQLite files rather than the
 * app connection, so a test can rewrite the source between cycles and watch
 * how the backup reacts.
 */
beforeEach(function () {
    $this->sourceFile = tempnam(sys_get_temp_dir(), 'db-sync-source-');
    $this->backupFile = tempnam(sys_get_temp_dir(), 'db-sync-backup-');

    config([
        'database.connections.db_sync_source' => [
            'driver' => 'sqlite',
            'database' => $this->sourceFile,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'database.connections.db_sync_backup' => [
            'driver' => 'sqlite',
            'database' => $this->backupFile,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'db_sync.source' => 'db_sync_source',
        'db_sync.backup' => 'db_sync_backup',
        // Small chunks so the multi-chunk paths are covered.
        'db_sync.chunk' => 2,
        'db_sync.reconcile_every' => 1000,
    ]);

    DB::purge('db_sync_source');
    DB::purge('db_sync_backup');

    Schema::connection('db_sync_source')->create('widgets', function ($table) {
        $table->id();
        $table->string('name');
        $table->integer('quantity')->nullable();
        $table->timestamps();
    });

    $this->seed = function (string $name, int $quantity = 1) {
        DB::connection('db_sync_source')->table('widgets')->insert([
            'name' => $name,
            'quantity' => $quantity,
            // Backdated so the watermark has clearly moved past these rows.
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    };

    $this->sync = fn (array $options = []) => Artisan::call('db:sync', ['--tables' => 'widgets'] + $options);

    $this->backupNames = fn () => DB::connection('db_sync_backup')->table('widgets')
        ->orderBy('id')->pluck('name')->all();

    $this->result = fn () => DB::connection('db_sync_backup')->table('sync_state')
        ->where('table_name', 'widgets')->value('last_result');
});

afterEach(function () {
    DB::purge('db_sync_source');
    DB::purge('db_sync_backup');

    @unlink($this->sourceFile);
    @unlink($this->backupFile);
});

it('mirrors every existing row into the backup database on the first run', function () {
    ($this->seed)('alpha');
    ($this->seed)('bravo');
    ($this->seed)('charlie');

    ($this->sync)();

    expect(Schema::connection('db_sync_backup')->hasTable('widgets'))->toBeTrue()
        ->and(($this->backupNames)())->toBe(['alpha', 'bravo', 'charlie'])
        ->and(($this->result)())->toBe('new');
});

it('reports none when nothing changed in the source', function () {
    ($this->seed)('alpha');

    ($this->sync)();
    ($this->sync)();

    expect(($this->result)())->toBe('none');
});

it('copies a new row into the backup', function () {
    ($this->seed)('alpha');

    ($this->sync)();
    ($this->seed)('bravo');
    ($this->sync)();

    expect(($this->backupNames)())->toBe(['alpha', 'bravo'])
        ->and(($this->result)())->toBe('new');
});

it('deletes a row from the backup when the source deletes it', function () {
    ($this->seed)('alpha');
    ($this->seed)('bravo');
    ($this->seed)('charlie');

    ($this->sync)();

    DB::connection('db_sync_source')->table('widgets')->where('name', 'bravo')->delete();

    ($this->sync)();

    expect(($this->backupNames)())->toBe(['alpha', 'charlie'])
        ->and(($this->result)())->toBe('deleted');
});

it('reports new and deleted data when both happen in one cycle', function () {
    ($this->seed)('alpha');
    ($this->seed)('bravo');

    ($this->sync)();

    DB::connection('db_sync_source')->table('widgets')->where('name', 'alpha')->delete();
    ($this->seed)('charlie');

    ($this->sync)();

    expect(($this->backupNames)())->toBe(['bravo', 'charlie'])
        ->and(($this->result)())->toBe('new+deleted');
});

it('drops the whole backup table contents when the source is emptied', function () {
    ($this->seed)('alpha');

    ($this->sync)();

    DB::connection('db_sync_source')->table('widgets')->delete();

    ($this->sync)();

    expect(DB::connection('db_sync_backup')->table('widgets')->count())->toBe(0)
        ->and(($this->result)())->toBe('deleted');
});

it('catches an update to an existing row without a new primary key', function () {
    ($this->seed)('alpha');

    ($this->sync)();

    DB::connection('db_sync_source')->table('widgets')->where('name', 'alpha')->update([
        'name' => 'renamed',
        'quantity' => 9,
        'updated_at' => now(),
    ]);

    ($this->sync)();

    $row = DB::connection('db_sync_backup')->table('widgets')->where('name', 'renamed')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->quantity)->toBe(9)
        ->and(($this->backupNames)())->toBe(['renamed'])
        ->and(($this->result)())->toBe('new');
});

it('reconciles drifted rows that matching row counts would hide', function () {
    ($this->seed)('alpha');
    ($this->seed)('bravo');

    ($this->sync)();

    // Source loses a row, backup keeps a phantom: the counts still agree.
    DB::connection('db_sync_source')->table('widgets')->where('name', 'bravo')->delete();
    DB::connection('db_sync_backup')->table('widgets')->insert([
        'id' => 999,
        'name' => 'phantom',
        'quantity' => 1,
    ]);

    ($this->sync)(['--reconcile' => true]);

    expect(($this->backupNames)())->toBe(['alpha']);
});

it('records a change event for new and deleted data', function () {
    ($this->seed)('alpha');
    ($this->seed)('bravo');

    ($this->sync)();

    DB::connection('db_sync_source')->table('widgets')->where('name', 'alpha')->delete();
    ($this->seed)('charlie');

    ($this->sync)();

    $event = DB::connection('db_sync_backup')->table('sync_events')->orderByDesc('id')->first();

    expect($event->table_name)->toBe('widgets')
        ->and($event->result)->toBe('new+deleted')
        ->and($event->new_rows)->toBe(1)
        ->and($event->deleted_rows)->toBe(1);
});

it('reports new or none for the operator through db:sync:status', function () {
    ($this->seed)('alpha');

    ($this->sync)();
    ($this->sync)();

    expect(Artisan::call('db:sync:status'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('widgets')
        ->and($output)->toContain('none');
});

it('fails with a clear message when the backup connection is unknown', function () {
    config(['db_sync.backup' => 'not-a-real-connection']);

    expect(($this->sync)())->toBe(1)
        ->and(Artisan::output())->toContain('not configured');
});
