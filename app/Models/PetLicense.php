<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * City/county license for a pet. Expires_on is what the Attention radar
 * watches — most jurisdictions renew yearly and the fee is modest, so
 * missing the window is the classic "oops" moment this row exists to
 * prevent.
 */
class PetLicense extends Model
{
    use BelongsToHousehold, HasMedia;

    protected $guarded = [];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'fee' => 'decimal:2',
    ];

    /** @return BelongsTo<Pet, $this> */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'proof_media_id');
    }
}
