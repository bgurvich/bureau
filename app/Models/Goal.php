<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal goal — two shapes:
 *   - mode=target     → target_value + (optional) target_date; progress + pace
 *   - mode=direction  → no numeric target; cadence_days drives staleness
 *
 * Goals are subject-linkable targets (in the polymorphic subject sense,
 * not the mode sense) — tasks, projects, journal entries, decisions,
 * etc. can point at a goal via their own subject pivots. Those linkages
 * are read via linkedTasks() / linkedProjects() here so the index can
 * surface "5 tasks, 3 done" without bespoke queries.
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
        'last_reflected_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDirection(): bool
    {
        return $this->mode === 'direction';
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
     * has no target_date (no schedule to pace against), no target_value
     * (direction goal), or hasn't started yet.
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

    /**
     * Direction-goal staleness: true when cadence_days is set and the
     * last_reflected_at + cadence has passed. Null for target goals,
     * directions with no cadence, or directions not yet reflected on.
     */
    public function isStale(): ?bool
    {
        if (! $this->isDirection() || $this->cadence_days === null) {
            return null;
        }
        if ($this->last_reflected_at === null) {
            return true;
        }

        return $this->last_reflected_at->addDays((int) $this->cadence_days)->isPast();
    }

    /**
     * Subjects pointing at this goal across the subject pivots. Each
     * host (Task, Project, etc.) keeps its own pivot table, so counting
     * by host requires one query per table — batched lazily and cached
     * on the model instance via standard relation caching is not
     * available for polymorphic inverse, so callers use the dedicated
     * linked* helpers below.
     */

    /** @return Builder<Task> */
    public function linkedTasks(): Builder
    {
        return Task::query()
            ->whereIn('id', function ($sub) {
                $sub->select('task_id')
                    ->from('task_subjects')
                    ->where('subject_type', static::class)
                    ->where('subject_id', $this->getKey());
            });
    }

    /** @return Builder<Project> */
    public function linkedProjects(): Builder
    {
        return Project::query()
            ->whereIn('id', function ($sub) {
                $sub->select('project_id')
                    ->from('project_subjects')
                    ->where('subject_type', static::class)
                    ->where('subject_id', $this->getKey());
            });
    }
}
