<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Scheduled tasks are defined here. Laravel Cloud invokes `schedule:run`
| every minute on the app compute cluster, so the frequency expressions
| below are evaluated by the framework's scheduler.
|
*/

// Mirror the live database into the Supabase connection: new and updated rows
// are copied across and rows deleted from the source are deleted from the
// mirror. With sub-minute frequencies Laravel's `schedule:run` stays alive for
// the whole minute, so this fires at :00 and :30 of each minute as long as the
// environment's Scheduler is enabled. Each cycle is also logged (db:sync) so
// failures show up in the Laravel Cloud Logs tab.
Schedule::command('db:sync')->everyThirtySeconds()->withoutOverlapping(5);

// Monthly: stage the previous month's system logs + staff login history and
// notify the admin (CSV attachment) so they can export to Excel or clear.
Schedule::call(function () {
    require_once __DIR__ . '/../public/api/_retention_helpers.php';
    stageMonthlyRetentionBatches();
})->name('retention.monthly')->monthlyOn(1, '00:30');

// Three-month order retention: stage + auto-purge completed sales/order history
// older than 3 months every month. The admin still gets the CSV archive email
// from the staging step before the records are automatically deleted.
Schedule::call(function () {
    require_once __DIR__ . '/../public/api/_retention_helpers.php';
    stageThreeMonthOrderBatches();
})->name('retention.orders-3-month')->monthlyOn(1, '02:30');
