<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\RecurringRule;
use App\Models\Subscription;
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

        // Sign-repair historic rows: an older code path stored
        // monthly_cost_cached as a positive magnitude; the current
        // contract is "preserve the rule's sign". Flip any remaining
        // positives whose linked rule is an outflow.
        $fixed = Subscription::query()
            ->where('monthly_cost_cached', '>', 0)
            ->whereHas('recurringRule', fn ($q) => $q->where('amount', '<', 0))
            ->get()
            ->each(function (Subscription $s) {
                $s->monthly_cost_cached = -1 * (float) $s->monthly_cost_cached;
                $s->save();
            })
            ->count();

        $this->info("  Backfilled {$created} subscription(s); linked {$linked} contract(s); sign-fixed {$fixed}.");

        return self::SUCCESS;
    }
}
