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
