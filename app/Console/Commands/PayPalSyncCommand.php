<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Integration;
use App\Support\CurrentHousehold;
use App\Support\PayPal\PayPalReconciliation;
use App\Support\PayPal\PayPalSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PayPalSyncCommand extends Command
{
    protected $signature = 'paypal:sync
        {--household= : Restrict to a single household id}
        {--integration= : Restrict to a single integration id}
        {--from= : Override start date (YYYY-MM-DD). Walks forward from there instead of the stored cursor — use for historical backfill.}';

    protected $description = 'Pull new PayPal Reporting API transactions and reconcile bank-row funding.';

    public function handle(PayPalSync $sync, PayPalReconciliation $recon): int
    {
        $householdId = $this->option('household');
        $integrationId = $this->option('integration');

        // Optional backfill start date. Wins over the stored cursor on
        // every integration touched by this run; cursor still advances
        // to "now" after completion so scheduled daily syncs then walk
        // forward normally.
        $fromOverride = null;
        $fromOpt = (string) ($this->option('from') ?? '');
        if ($fromOpt !== '') {
            try {
                $fromOverride = CarbonImmutable::parse($fromOpt)->startOfDay();
            } catch (\Throwable) {
                $this->error("Invalid --from date: {$fromOpt}. Use YYYY-MM-DD.");

                return self::FAILURE;
            }
            if ($fromOverride->isFuture()) {
                $this->error("--from is in the future: {$fromOpt}.");

                return self::FAILURE;
            }
            $this->line("  Historical backfill from {$fromOverride->toDateString()} (overrides stored cursor).");
        }

        $households = Household::query()
            ->when($householdId, fn ($q) => $q->where('id', $householdId))
            ->get();

        $totalCreated = 0;
        $totalLinked = 0;

        foreach ($households as $household) {
            CurrentHousehold::set($household);

            $integrations = Integration::query()
                ->where('provider', 'paypal')
                ->where('status', 'active')
                ->when($integrationId, fn ($q) => $q->where('id', $integrationId))
                ->get();

            foreach ($integrations as $integration) {
                $this->line("  [{$household->name}] syncing PayPal · {$integration->label}");
                $created = $sync->sync($integration, $fromOverride);
                $totalCreated += $created;
            }

            // Run reconciliation once per household after PayPal rows land.
            if ($integrations->isNotEmpty()) {
                $linked = $recon->reconcile($household);
                $totalLinked += $linked;
            }
        }

        $this->info("  PayPal sync complete — {$totalCreated} transactions, {$totalLinked} children linked.");

        return self::SUCCESS;
    }
}
