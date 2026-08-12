<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Email the owner an end-of-day sales summary. Requires a cron entry running
// `php artisan schedule:run` every minute (standard Laravel deployment).
Schedule::command('orders:end-of-day-report')->dailyAt('01:00')->timezone('Asia/Manila');
