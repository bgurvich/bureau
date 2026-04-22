<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetVaccination extends Model
{
    use BelongsToHousehold, HasMedia;

    protected $guarded = [];

    protected $casts = [
        'administered_on' => 'date',
        'valid_until' => 'date',
        'booster_due_on' => 'date',
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

    /** @return BelongsTo<Media, $this> */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'proof_media_id');
    }
}
