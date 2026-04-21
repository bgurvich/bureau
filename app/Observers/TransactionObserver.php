<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Support\CategoryRuleMatcher;
use App\Support\TagRuleMatcher;

/**
 * When a transaction is created:
 *   - apply category rules (sets category_id if matched; explicit beats inferred)
 *   - apply tag rules (attaches matching tags; additive, never detaches)
 */
class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        CategoryRuleMatcher::attempt($transaction);
        TagRuleMatcher::attempt($transaction);
    }
}
