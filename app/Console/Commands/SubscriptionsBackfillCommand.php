<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\RecurringRule;
use App\Support\SubscriptionSync;
use Illuminate\Console\Command;

/**
 * One-off backfill: turn every existing active outflow RecurringRule into
 * a Subscription (idempotent — rules that already have one are skipped).
 * Then re-link contracts for any subscriptions still missing a contract.
 *
 * Safe to re-run; use after deploying the subscriptions table to seed
 * from historical data.
 */
class SubscriptionsBackfillCommand extends Command
{
    protected $signature = 'subscriptions:backfill';

    protected $description = 'Backfill Subscription rows from existing RecurringRules and link Contracts by counterparty';

    public function handle(): int
    {
        $created = 0;
        RecurringRule::where('active', true)
            ->where('amount', '<', 0)
            ->chunk(200, function ($rules) use (&$created) {
                foreach ($rules as $rule) {
                    if (SubscriptionSync::fromRecurringRule($rule)) {
                        $created++;
                    }
                }
            });

        $linked = 0;
        Contract::all()->each(function ($c) use (&$linked) {
            $linked += SubscriptionSync::linkContract($c);
        });

        $this->info("  Backfilled {$created} subscription(s); linked {$linked} contract(s).");

        return self::SUCCESS;
    }
}
