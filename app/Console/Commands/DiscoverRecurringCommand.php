<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Support\RecurringPatternDiscovery;
use Illuminate\Console\Command;

class DiscoverRecurringCommand extends Command
{
    protected $signature = 'recurring:discover
        {--household= : Restrict to a single household id}';

    protected $description = 'Scan transactions for recurring patterns and propose RecurringDiscovery rows. Idempotent.';

    public function handle(RecurringPatternDiscovery $discovery): int
    {
        $filter = $this->option('household');
        $households = Household::query()
            ->when($filter, fn ($q) => $q->where('id', $filter))
            ->get();

        $total = 0;
        foreach ($households as $household) {
            $count = $discovery->discover($household);
            $total += $count;
            $this->line("  [{$household->name}] {$count} new pattern(s)");
        }

        $this->info("  Discovery complete — {$total} new proposal(s) across ".$households->count().' household(s).');

        return self::SUCCESS;
    }
}
