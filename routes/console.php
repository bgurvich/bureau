<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('recurring:project')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('snapshots:rollup')
    ->monthlyOn(2, '04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Nightly DB dump + media sync to storage/app/private/Laravel/*.zip
// (spatie/laravel-backup config: config/backup.php).
Schedule::command('backup:clean')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:run')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Alerts if the backup set hasn't been touched within the configured window.
Schedule::command('backup:monitor')
    ->dailyAt('12:00')
    ->withoutOverlapping()
    ->onOneServer();
