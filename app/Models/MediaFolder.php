<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'last_scanned_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }
}
