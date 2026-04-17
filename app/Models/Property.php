<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\HasValuations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use BelongsToHousehold, HasTags, HasMedia, HasValuations;

    protected $guarded = [];

    protected $casts = [
        'address' => 'array',
        'acquired_on' => 'date',
        'disposed_on' => 'date',
        'purchase_price' => 'decimal:4',
        'size_value' => 'decimal:2',
    ];

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'location_property_id');
    }
}
