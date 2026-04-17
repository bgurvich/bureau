<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\HasValuations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    use BelongsToHousehold, HasMedia, HasTags, HasValuations;

    protected $guarded = [];

    protected $casts = [
        'purchased_on' => 'date',
        'cost_amount' => 'decimal:4',
        'warranty_expires_on' => 'date',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'location_property_id');
    }

    public function purchasedFrom(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'purchased_from_contact_id');
    }
}
