<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Nightly cron: flip paused subscriptions whose paused_until has arrived
 * back to active. Clears the paused_until marker on resume so the next
 * pause starts fresh. Idempotent — already-active rows are skipped.
 */
class ResumePausedSubscriptions extends Command
{
    protected $signature = 'subscriptions:resume-due';

    protected $description = 'Resume paused subscriptions whose paused_until has arrived';

    public function handle(): int
    {
        $affected = Subscription::withoutGlobalScopes()
            ->where('state', 'paused')
            ->whereNotNull('paused_until')
            ->whereDate('paused_until', '<=', now()->toDateString())
            ->update(['state' => 'active', 'paused_until' => null]);

        $this->info("  Resumed {$affected} subscription(s).");

        return self::SUCCESS;
    }
}
