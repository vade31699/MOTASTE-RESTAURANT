<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DbSyncStatus extends Command
{
    protected $signature = 'db:sync:status
        {--events=10 : How many recent change events to list}
        {--json : Print the status as JSON}';

    protected $description = 'Show whether the backup database has received new or deleted data';

    public function handle(): int
    {
        $backup = (string) config('db_sync.backup');
        $schema = Schema::connection($backup);

        if (! $schema->hasTable('sync_state')) {
            $this->error("No mirror state on [{$backup}]. Run `php artisan db:sync` first.");

            return self::FAILURE;
        }

        $connection = DB::connection($backup);

        $states = $connection->table('sync_state')
            ->where('table_name', '!=', DbSync::CYCLE_ROW)
            ->orderBy('table_name')
            ->get();

        $cycle = $connection->table('sync_state')->where('table_name', DbSync::CYCLE_ROW)->first();

        $limit = max(0, (int) $this->option('events'));

        $events = $limit > 0 && $schema->hasTable('sync_events')
            ? $connection->table('sync_events')->orderByDesc('id')->limit($limit)->get()
            : collect();

        if ($this->option('json')) {
            $this->line(json_encode([
                'connection' => $backup,
                'last_cycle_at' => $cycle->last_run_at ?? null,
                'last_reconciled_at' => $cycle->last_reconciled_at ?? null,
                'tables' => $states,
                'events' => $events,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('Mirror: '.$backup);
        $this->line('Last cycle: '.($cycle->last_run_at ?? 'never'));
        $this->line('Last reconciliation: '.($cycle->last_reconciled_at ?? 'never'));
        $this->newLine();

        $this->table(
            ['Table', 'Last run', 'New/Updated', 'Deleted', 'Result'],
            $states->map(fn ($state) => [
                $state->table_name,
                $state->last_run_at,
                $state->rows_upserted,
                $state->rows_deleted,
                $state->last_result,
            ])->all(),
        );

        if ($events->isNotEmpty()) {
            $this->newLine();
            $this->line('Recent changes (newest first):');

            $this->table(
                ['When', 'Table', 'New', 'Deleted', 'Result'],
                $events->map(fn ($event) => [
                    $event->created_at,
                    $event->table_name,
                    $event->new_rows,
                    $event->deleted_rows,
                    $event->result,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
