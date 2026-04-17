<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\HasValuations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use BelongsToHousehold, HasTags, HasMedia, HasValuations;

    protected $guarded = [];

    protected $casts = [
        'acquired_on' => 'date',
        'disposed_on' => 'date',
        'purchase_price' => 'decimal:4',
    ];

    public function primaryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_user_id');
    }
}
