<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetCap extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'monthly_cap' => 'decimal:4',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
