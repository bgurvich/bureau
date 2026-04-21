<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Prescription extends Model
{
    use BelongsToHousehold, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'active_from' => 'date',
        'active_to' => 'date',
        'next_refill_on' => 'date',
    ];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<HealthProvider, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(HealthProvider::class, 'prescriber_id');
    }
}
