<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
