<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Support\TransferPairing;
use Illuminate\Console\Command;

class PairTransfersCommand extends Command
{
    protected $signature = 'transfers:pair
        {--household= : Restrict to a single household id}';

    protected $description = 'Detect bank-to-bank transfer pairs among unmatched Transactions and collapse them to Transfer rows.';

    public function handle(TransferPairing $pairing): int
    {
        $filter = $this->option('household');
        $households = Household::query()
            ->when($filter, fn ($q) => $q->where('id', $filter))
            ->get();

        $total = 0;
        foreach ($households as $household) {
            $count = $pairing->pair($household);
            $total += $count;
            $this->line("  [{$household->name}] {$count} transfer pair(s) created");
        }

        $this->info("  Pairing complete — {$total} pair(s) across ".$households->count().' household(s).');

        return self::SUCCESS;
    }
}
