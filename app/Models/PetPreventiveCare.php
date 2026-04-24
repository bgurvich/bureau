<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per preventive-care application on a pet. Latest row per
 * (pet, kind) is the active reminder; older rows are history.
 */
class PetPreventiveCare extends Model
{
    use BelongsToHousehold;

    protected $table = 'pet_preventive_care';

    protected $guarded = [];

    protected $casts = [
        'applied_on' => 'date',
        'next_due_on' => 'date',
        'interval_days' => 'integer',
        'cost' => 'decimal:2',
    ];

    /** @return BelongsTo<Pet, $this> */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /** @return BelongsTo<HealthProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(HealthProvider::class, 'provider_id');
    }
}
