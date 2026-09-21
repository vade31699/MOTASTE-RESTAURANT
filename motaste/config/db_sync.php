<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Rows are read from `source` (the live database) and written to `backup`
    | (the mirror). Both names refer to entries in config/database.php.
    |
    */

    'source' => env('DB_SYNC_SOURCE', env('DB_CONNECTION', 'sqlite')),

    'backup' => env('DB_SYNC_BACKUP', 'supabase'),

    /*
    |--------------------------------------------------------------------------
    | Cadence
    |--------------------------------------------------------------------------
    |
    | `interval` is how long `db:sync --loop` sleeps between cycles. Note that
    | Laravel's scheduler is driven by `schedule:run`, which runs once a minute
    | on Laravel Cloud, so a scheduled `db:sync` fires at most once per minute
    | no matter what frequency is declared. Use `--loop` for a true 30s cadence.
    |
    | `reconcile_every` is how many intervals pass between full key-set
    | reconciliations. Reconciling compares every primary key on both sides,
    | which is more expensive than a row count, so it runs occasionally rather
    | than on every cycle. It is the safety net that catches a backup that has
    | drifted even though the row counts agree.
    |
    | `chunk` is how many rows are copied per statement, and `safety_margin` is
    | how many seconds of recent activity are re-checked on each cycle so a row
    | written while a cycle was running cannot slip through the watermark.
    |
    */

    'interval' => (int) env('DB_SYNC_INTERVAL', 30),

    'reconcile_every' => (int) env('DB_SYNC_RECONCILE_EVERY', 10),

    'chunk' => (int) env('DB_SYNC_CHUNK', 500),

    'safety_margin' => (int) env('DB_SYNC_SAFETY_MARGIN', 2),

    /*
    |--------------------------------------------------------------------------
    | Mirrored Tables
    |--------------------------------------------------------------------------
    |
    | Business data is mirrored. Framework bookkeeping (sessions, cache, jobs)
    | and short-lived credentials (session tokens, reset codes, trusted devices)
    | are deliberately left out: they change constantly, have no value in a
    | backup, and copying secrets into a second database only widens exposure.
    | Override with DB_SYNC_TABLES or `db:sync --tables=`.
    |
    */

    'tables' => env('DB_SYNC_TABLES')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('DB_SYNC_TABLES')))))
        : [
            'admins',
            'staff',
            'staff_login_history',
            'users',
            'inventory_items',
            'orders',
            'order_items',
            'order_activity_logs',
            'customer_reviews',
            'review_activity_logs',
            'review_daily_blocks',
            'custom_menu_snapshots',
            'highlights_snapshots',
            'loyalty_accounts',
            'loyalty_transactions',
            'data_retention_batches',
        ],

];
