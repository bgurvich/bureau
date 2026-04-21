<?php

namespace App\Observers;

use App\Models\RecurringRule;
use App\Support\SubscriptionSync;

class RecurringRuleObserver
{
    public function created(RecurringRule $rule): void
    {
        SubscriptionSync::fromRecurringRule($rule);
    }
}
