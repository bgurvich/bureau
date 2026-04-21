<?php

namespace App\Models\Concerns;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Gives any domain model the inverse side of the polymorphic transaction↔
 * subject relationship. `$property->transactions` returns every Transaction
 * that lists this property in its transaction_subjects pivot. Symmetric
 * with HasLinkedTasks / HasLinkedNotes.
 */
trait HasLinkedTransactions
{
    /**
     * @return MorphToMany<Transaction, $this>
     */
    public function linkedTransactions(): MorphToMany
    {
        return $this->morphToMany(Transaction::class, 'subject', 'transaction_subjects');
    }
}
