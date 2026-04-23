<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Household;
use App\Models\RecurringRule;
use App\Models\Subscription;
use App\Support\CurrentHousehold;
use App\Support\SubscriptionSync;
use Illuminate\Console\Command;

/**
 * Destructive counterpart to `subscriptions:backfill`. Wipes every
 * Subscription row for the target household(s) and re-creates them from
 * the current active outflow RecurringRule set. Useful when the
 * heuristic that builds subscriptions changes (e.g. sign-convention
 * fix, different monthly-cost derivation) and the user wants the
 * table rebuilt cleanly rather than lived-with as patchwork data.
 *
 * Manually-edited subscription fields (name, counterparty, paused_until,
 * notes) are LOST. Use `subscriptions:backfill` for non-destructive
 * repair of the same state. Asks for confirmation unless --force.
 */
class SubscriptionsRegenerateCommand extends Command
{
    protected $signature = 'subscriptions:regenerate
        {--household= : Household id; defaults to every household}
        {--dry-run : Print the counts and exit without deleting}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Delete all Subscription rows and recreate them from active outflow RecurringRules. Destructive — manual edits to subscriptions are lost.';

    public function handle(): int
    {
        $households = Household::query()
            ->when($this->option('household'), fn ($q) => $q->whereKey($this->option('household')))
            ->get();

        if ($households->isEmpty()) {
            $this->warn('No households matched — nothing to do.');

            return self::SUCCESS;
        }

        foreach ($households as $household) {
            CurrentHousehold::set($household);

            $existing = Subscription::where('household_id', $household->id)->count();
            $ruleCount = RecurringRule::where('household_id', $household->id)
                ->where('active', true)
                ->where('amount', '<', 0)
                ->count();

            $this->line('');
            $this->line("<comment>[{$household->name}] household #{$household->id}</comment>");
            $this->line("  Existing subscriptions: {$existing}");
            $this->line("  Active outflow rules to rebuild from: {$ruleCount}");

            if ($this->option('dry-run')) {
                continue;
            }

            if (! $this->option('force') && ! $this->confirm("Delete all {$existing} subscription(s) for household #{$household->id} and recreate from {$ruleCount} rule(s)? Manual edits will be lost.")) {
                $this->warn('  Skipped.');

                continue;
            }

            $deleted = Subscription::where('household_id', $household->id)->delete();

            $created = 0;
            RecurringRule::where('household_id', $household->id)
                ->where('active', true)
                ->where('amount', '<', 0)
                ->chunk(200, function ($rules) use (&$created) {
                    foreach ($rules as $rule) {
                        if (SubscriptionSync::fromRecurringRule($rule)) {
                            $created++;
                        }
                    }
                });

            // Re-link contracts — linkContract walks every contract and
            // back-fills contract_id on subscriptions sharing the
            // counterparty. Household-scoped via Contract's own global
            // scope; no per-household filter needed here.
            $linked = 0;
            Contract::all()->each(function ($c) use (&$linked) {
                $linked += SubscriptionSync::linkContract($c);
            });

            $this->info("  Deleted {$deleted}; recreated {$created}; linked {$linked} contract(s).");
        }

        return self::SUCCESS;
    }
}
