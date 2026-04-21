<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Integration;
use App\Support\CurrentHousehold;
use App\Support\PayPal\PayPalReconciliation;
use App\Support\PayPal\PayPalSync;
use Illuminate\Console\Command;

class PayPalSyncCommand extends Command
{
    protected $signature = 'paypal:sync
        {--household= : Restrict to a single household id}
        {--integration= : Restrict to a single integration id}';

    protected $description = 'Pull new PayPal Reporting API transactions and reconcile bank-row funding.';

    public function handle(PayPalSync $sync, PayPalReconciliation $recon): int
    {
        $householdId = $this->option('household');
        $integrationId = $this->option('integration');

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
                $created = $sync->sync($integration);
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
