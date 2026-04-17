<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'as_of' => 'date',
        'rate' => 'decimal:8',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
