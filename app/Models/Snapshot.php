<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Snapshot extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'taken_on' => 'date',
        'payload' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
