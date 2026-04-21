<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Observers\ContractObserver;
use App\Observers\RecurringRuleObserver;
use App\Observers\TransactionObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Transaction::observe(TransactionObserver::class);
        RecurringRule::observe(RecurringRuleObserver::class);
        Contract::observe(ContractObserver::class);
    }
}
