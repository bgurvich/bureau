<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetCheckup extends Model
{
    use BelongsToHousehold, HasMedia;

    protected $guarded = [];

    protected $casts = [
        'checkup_on' => 'date',
        'next_due_on' => 'date',
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
