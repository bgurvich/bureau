<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    /** @return BelongsTo<Tag, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'parent_id');
    }

    /** @return HasMany<Tag, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Tag::class, 'parent_id');
    }
}
