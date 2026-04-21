<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagRule extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
