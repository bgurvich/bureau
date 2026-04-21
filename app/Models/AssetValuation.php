<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AssetValuation extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'as_of' => 'date',
        'value' => 'decimal:4',
    ];

    /** @return MorphTo<Model, $this> */
    public function valuable(): MorphTo
    {
        return $this->morphTo();
    }
}
