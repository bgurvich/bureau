<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;

class RadarSnooze extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'snoozed_until' => 'datetime',
    ];
}
