<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'remind_at' => 'datetime',
        'fired_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }
}
