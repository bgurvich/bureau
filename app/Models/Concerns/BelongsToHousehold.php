<?php

namespace App\Models\Concerns;

use App\Models\Household;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToHousehold
{
    public static function bootBelongsToHousehold(): void
    {
        static::addGlobalScope('household', function (Builder $builder) {
            if ($id = CurrentHousehold::id()) {
                $builder->where($builder->getModel()->getTable().'.household_id', $id);
            }
        });

        static::creating(function ($model) {
            if ($model->household_id === null && ($id = CurrentHousehold::id()) !== null) {
                $model->household_id = $id;
            }
        });
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
