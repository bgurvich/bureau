<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (item, platform) posting. Manual entry today; the
 * auto-post adapters (eBay Sell API, Craigslist bulkpost XML) will
 * plug into pre/post-save hooks later.
 */
class Listing extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'sold_for' => 'decimal:2',
        'posted_on' => 'date',
        'expires_on' => 'date',
        'ended_on' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function soldTo(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sold_to_contact_id');
    }
}
