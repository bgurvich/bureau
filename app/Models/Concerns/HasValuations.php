<?php

namespace App\Models\Concerns;

use App\Models\AssetValuation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasValuations
{
    public function valuations(): MorphMany
    {
        return $this->morphMany(AssetValuation::class, 'valuable');
    }

    public function latestValuation()
    {
        return $this->morphOne(AssetValuation::class, 'valuable')->latestOfMany('as_of');
    }
}
