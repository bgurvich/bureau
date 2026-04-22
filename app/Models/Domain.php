<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owned web / DNS domain. First-class on the Assets hub alongside
 * Property / Vehicle / InventoryItem. Expiry feeds the attention
 * radar; no media / valuation / linked-notes relations unless a real
 * use case shows up.
 */
class Domain extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'registered_on' => 'date',
        'expires_on' => 'date',
        'auto_renew' => 'boolean',
        'annual_cost' => 'decimal:2',
    ];

    /** @return BelongsTo<Contact, $this> */
    public function registrant(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'registrant_contact_id');
    }
}
