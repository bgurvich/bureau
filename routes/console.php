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

// Restore the latest archive into a throwaway DB and compare row counts —
// the only signal that separates "backups are running" from "backups are
// actually usable." Non-zero exit surfaces via emailOutputOnFailure.
Schedule::command('snapshots:verify-restore')
    ->monthlyOn(3, '05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure((string) config('backup.notifications.mail.to') ?: 'root@localhost');

// Reminders: generate daily from date-bearing records, fire every 5 minutes.
Schedule::command('reminders:generate')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reminders:fire')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Pull new messages from every active mail integration (JMAP + Gmail).
// Postmark is push-only and bypasses this. 10-minute cadence balances
// responsiveness against Gmail quota use. Incremental cursors make each run cheap.
Schedule::command('mail:sync')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Scan transactions for new recurring patterns weekly. Idempotent — dismissed
// or accepted discoveries aren't re-proposed. User can trigger manually from
// the Bills page when they want an immediate refresh (e.g. post-import).
Schedule::command('recurring:discover')
    ->weeklyOn(1, '05:00')
    ->withoutOverlapping()
    ->onOneServer();

// PayPal sync + bank-row reconciliation — hourly is a reasonable cadence for
// consumer PayPal activity. Webhook gets any real-time events; poll catches
// anything that slipped through.
Schedule::command('paypal:sync')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Weekly "what changed" digest — Sundays 17:00 local. Idempotent wrt content
// but intentionally not wrt delivery: running twice in one day mails twice.
Schedule::command('digest:weekly')
    ->weeklyOn(0, '17:00')
    ->withoutOverlapping()
    ->onOneServer();

// Pair OCR-extracted receipts with outflow Transactions nightly. Safe to re-
// run — processed_at guard skips already-paired media.
Schedule::command('receipts:match')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onOneServer();

// Collapse bank-to-bank transfer pairs among unmatched Transactions into
// Transfer rows nightly. Ambiguous matches (>1 candidate credit per debit)
// are skipped — the manual Transfer inspector handles those.
Schedule::command('transfers:pair')
    ->dailyAt('02:50')
    ->withoutOverlapping()
    ->onOneServer();

// Flip paused subscriptions back to active when paused_until arrives.
// Daily is fine — no urgency; more frequent runs would just cost queries.
Schedule::command('subscriptions:resume-due')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

// Fire in-app reminders for savings goals crossing 25/50/75/100% of target.
// Daily — progress moves slowly; same-day detection is fine.
Schedule::command('savings:milestones')
    ->dailyAt('04:45')
    ->withoutOverlapping()
    ->onOneServer();
