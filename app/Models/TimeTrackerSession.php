<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TimeTrackerSession extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'accumulated_seconds' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Current elapsed seconds, including the live running segment if any.
     */
    public function elapsedSeconds(?Carbon $now = null): int
    {
        $now ??= now();
        $total = $this->accumulated_seconds;

        if ($this->status === 'running' && $this->started_at) {
            $total += max(0, (int) $this->started_at->diffInSeconds($now, absolute: true));
        }

        return $total;
    }
}
