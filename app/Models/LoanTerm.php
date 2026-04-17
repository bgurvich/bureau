<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanTerm extends Model
{
    protected $guarded = [];

    protected $casts = [
        'principal' => 'decimal:4',
        'interest_rate' => 'decimal:5',
        'originated_on' => 'date',
        'matures_on' => 'date',
        'monthly_payment_amount' => 'decimal:4',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
