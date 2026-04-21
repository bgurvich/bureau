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
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasMedia, HasTags, HasValuations;

    protected $guarded = [];

    protected $casts = [
        'address' => 'array',
        'acquired_on' => 'date',
        'disposed_on' => 'date',
        'purchase_price' => 'decimal:4',
        'size_value' => 'decimal:2',
        'sale_amount' => 'decimal:4',
    ];

    /** @return HasMany<InventoryItem, $this> */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'location_property_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }
}
