<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single tax year for a single jurisdiction (US-federal or a state).
 * Container for docs + quarterly payments. We intentionally don't model
 * a "filing status graph" — free-form state column reads "prep",
 * "filed", "amended" etc. and only the Attention radar cares.
 */
class TaxYear extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'filed_on' => 'date',
        'settlement_amount' => 'decimal:2',
    ];

    /** @return HasMany<TaxDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(TaxDocument::class);
    }

    /** @return HasMany<TaxEstimatedPayment, $this> */
    public function estimatedPayments(): HasMany
    {
        return $this->hasMany(TaxEstimatedPayment::class);
    }
}
