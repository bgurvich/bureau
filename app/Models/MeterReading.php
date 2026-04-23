<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single utility meter reading for a Property — water/electric/gas.
 * Consumption deltas are derived at read-time by ordering siblings on
 * read_on and subtracting the prior value; no materialised "delta"
 * column to keep rolled-back edits from leaving stale numbers.
 */
class MeterReading extends Model
{
    use HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'read_on' => 'date',
        'value' => 'decimal:4',
    ];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
