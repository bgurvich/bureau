<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on the user's reading/watching/listening list. Kept
 * household-scoped so partners can share a wishlist; `user_id` on the
 * row marks who added / owns the entry for personal streams.
 */
class MediaLogEntry extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'started_on' => 'date',
        'finished_on' => 'date',
        'rating' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
