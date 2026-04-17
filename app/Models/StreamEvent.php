<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StreamEvent extends Model
{
    use BelongsToHousehold;

    protected $table = 'events_stream';

    protected $guarded = [];

    protected $casts = [
        'happened_at' => 'datetime',
        'payload' => 'array',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
