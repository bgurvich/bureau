<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Support\CategoryRuleMatcher;
use Illuminate\Console\Command;

/**
 * One-off re-categorization sweep. Useful after adding a new rule — future
 * transactions get categorized by the observer, but historical ones need a
 * manual pass. Skips transactions that already have a category (the rule
 * engine never overrides a manual choice).
 */
class ApplyCategoryRulesCommand extends Command
{
    protected $signature = 'categories:apply
                            {--limit=5000 : Max transactions to scan this run}';

    protected $description = 'Apply category rules to uncategorized transactions';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $matched = 0;
        Transaction::whereNull('category_id')
            ->orderByDesc('occurred_on')
            ->limit($limit)
            ->get()
            ->each(function (Transaction $t) use (&$matched) {
                if (CategoryRuleMatcher::attempt($t)) {
                    $matched++;
                }
            });

        $this->info("  Categorized {$matched} transaction(s).");

        return self::SUCCESS;
    }
}
