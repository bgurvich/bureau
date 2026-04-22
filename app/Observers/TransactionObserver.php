<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Support\CategoryRuleMatcher;
use App\Support\ContactCategoryApplier;
use App\Support\TagRuleMatcher;

/**
 * When a transaction is created:
 *   - apply the counterparty contact's default category (strongest signal
 *     short of an explicit user-set category)
 *   - apply category rules (description-pattern match; fills in for
 *     contacts without a default category)
 *   - apply tag rules (attaches matching tags; additive, never detaches)
 */
class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        ContactCategoryApplier::attempt($transaction);
        CategoryRuleMatcher::attempt($transaction);
        TagRuleMatcher::attempt($transaction);
    }
}
