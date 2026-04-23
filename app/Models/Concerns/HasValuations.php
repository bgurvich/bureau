<?php

namespace App\Models\Concerns;

use App\Models\AssetValuation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasValuations
{
    /** @return MorphMany<AssetValuation, $this> */
    public function valuations(): MorphMany
    {
        return $this->morphMany(AssetValuation::class, 'valuable');
    }

    /** @return MorphOne<AssetValuation, $this> */
    public function latestValuation(): MorphOne
    {
        return $this->morphOne(AssetValuation::class, 'valuable')->latestOfMany('as_of');
    }
}
