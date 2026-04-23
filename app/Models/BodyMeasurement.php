<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One body-composition sample. Weight in kg; the UI handles lb/kg
 * presentation based on user profile (US uses lb).
 */
class BodyMeasurement extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'measured_at' => 'datetime',
        'weight_kg' => 'decimal:2',
        'body_fat_pct' => 'decimal:2',
        'muscle_pct' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Convenience: kg → lb. Returns null if weight_kg is null. */
    public function weightLb(): ?float
    {
        return $this->weight_kg !== null ? (float) $this->weight_kg * 2.20462 : null;
    }
}
