<?php

namespace App\Console\Commands;

use App\Support\SavingsMilestoneTracker;
use Illuminate\Console\Command;

class CheckSavingsMilestonesCommand extends Command
{
    protected $signature = 'savings:milestones';

    protected $description = 'Fire a reminder when a savings goal crosses 25/50/75/100% of its target';

    public function handle(): int
    {
        $created = SavingsMilestoneTracker::sweep();
        $this->info("  Created {$created} milestone reminder(s).");

        return self::SUCCESS;
    }
}
