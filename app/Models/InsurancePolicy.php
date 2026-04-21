<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsurancePolicy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'premium_amount' => 'decimal:4',
        'coverage_amount' => 'decimal:4',
        'deductible_amount' => 'decimal:4',
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'carrier_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'broker_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'beneficiary_contact_id');
    }

    /** @return HasMany<InsurancePolicySubject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(InsurancePolicySubject::class, 'policy_id');
    }
}
