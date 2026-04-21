<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use BelongsToHousehold, HasTags;

    protected $table = 'media';

    protected $guarded = [];

    protected $casts = [
        'captured_at' => 'datetime',
        'meta' => 'array',
        'ocr_extracted' => 'array',
        'processed_at' => 'datetime',
        'size' => 'integer',
    ];

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }
}
