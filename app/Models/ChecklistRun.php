<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistRun extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'run_date' => 'date',
        'ticked_item_ids' => 'array',
        'completed_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /** @return array<int, int> */
    public function tickedIds(): array
    {
        $raw = $this->ticked_item_ids;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map('intval', $raw));
    }

    public function tick(int $itemId): void
    {
        $ids = $this->tickedIds();
        if (! in_array($itemId, $ids, true)) {
            $ids[] = $itemId;
            $this->ticked_item_ids = $ids;
        }
    }

    public function untick(int $itemId): void
    {
        $ids = array_values(array_filter($this->tickedIds(), fn (int $i) => $i !== $itemId));
        $this->ticked_item_ids = $ids;
    }
}
