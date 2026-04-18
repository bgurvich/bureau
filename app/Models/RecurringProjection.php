<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringProjection extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'due_on' => 'date',
        'issued_on' => 'date',
        'amount' => 'decimal:4',
        'autopay' => 'boolean',
        'matched_at' => 'datetime',
        'unmatched_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class, 'rule_id');
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }

    public function matchedTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'matched_transfer_id');
    }
}
