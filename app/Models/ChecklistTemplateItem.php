<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistTemplateItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
