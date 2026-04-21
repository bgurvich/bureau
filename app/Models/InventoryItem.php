<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\HasValuations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasMedia, HasTags, HasValuations;

    protected $guarded = [];

    protected $casts = [
        'purchased_on' => 'date',
        'cost_amount' => 'decimal:4',
        'warranty_expires_on' => 'date',
        'return_by' => 'date',
        'processed_at' => 'datetime',
        'disposed_on' => 'date',
        'sale_amount' => 'decimal:4',
        'is_for_sale' => 'boolean',
        'listing_asking_amount' => 'decimal:4',
        'listing_posted_at' => 'date',
    ];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'location_property_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function purchasedFrom(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'purchased_from_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }
}
