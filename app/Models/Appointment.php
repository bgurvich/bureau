<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Appointment extends Model
{
    use BelongsToHousehold, HasTags, HasMedia;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(HealthProvider::class, 'provider_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
