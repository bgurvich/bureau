<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One food-intake row. Manual entries only in v1; a future OCR flow
 * will set source='photo' when a local-LLM pipeline identifies plates.
 */
class FoodEntry extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'eaten_at' => 'datetime',
        'servings' => 'decimal:2',
        'calories' => 'integer',
        'protein_g' => 'decimal:1',
        'carbs_g' => 'decimal:1',
        'fat_g' => 'decimal:1',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
