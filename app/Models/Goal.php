<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal goal — a target value the user is tracking toward a
 * deadline. Progress lives in current_value; progress() + onTrack()
 * are derived.
 */
class Goal extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'started_on' => 'date',
        'target_date' => 'date',
        'achieved_on' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 0.0–1.0 progress ratio. Clamped so over-hitting a target doesn't blow the bar out. */
    public function progress(): float
    {
        $target = (float) $this->target_value;
        if ($target <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $this->current_value / $target));
    }

    /**
     * True when pacing >= time-elapsed ratio. Returns null when a goal
     * has no target_date (no schedule to pace against) or hasn't started
     * yet (no elapsed time to compare against).
     */
    public function onTrack(): ?bool
    {
        $target = (float) $this->target_value;
        if ($target <= 0 || ! $this->target_date || ! $this->started_on) {
            return null;
        }

        $totalDays = (float) $this->started_on->diffInDays($this->target_date, absolute: true);
        if ($totalDays <= 0) {
            return null;
        }
        $elapsedDays = (float) $this->started_on->diffInDays(now()->startOfDay(), absolute: false);
        if ($elapsedDays <= 0) {
            return null;
        }

        $expectedRatio = min(1.0, $elapsedDays / $totalDays);

        return $this->progress() >= $expectedRatio;
    }
}
