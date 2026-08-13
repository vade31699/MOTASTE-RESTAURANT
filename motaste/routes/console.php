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

// Semi-annual: stage sales/order history older than 6 months and notify the
// admin so they can export or purge the records.
Schedule::call(function () {
    require_once __DIR__ . '/../public/api/_retention_helpers.php';
    stageSixMonthOrderBatches();
})->name('retention.six-month')->cron('0 2 1 */6 *');
