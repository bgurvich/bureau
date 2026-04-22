<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quarterly estimated-tax row (or state equivalent) on a TaxYear.
 * `paid_on` is what flips the "paid" badge — until then the row is a
 * projected commitment. The account_id attaches on payment so the
 * ledger trail is traceable.
 */
class TaxEstimatedPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'due_on' => 'date',
        'paid_on' => 'date',
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<TaxYear, $this> */
    public function taxYear(): BelongsTo
    {
        return $this->belongsTo(TaxYear::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
