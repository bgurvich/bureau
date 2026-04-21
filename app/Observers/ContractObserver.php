<?php

namespace App\Observers;

use App\Models\Contract;
use App\Support\SubscriptionSync;

class ContractObserver
{
    public function created(Contract $contract): void
    {
        SubscriptionSync::linkContract($contract);
    }
}
