<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    use BelongsToHousehold, HasMedia, HasSubjects, HasTags;

    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'priority' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Tasks that must be completed before this one becomes active. The
     * task is "blocked" as long as any predecessor is not in state=done.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function predecessors(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Task, $this> */
    public function successors(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id')
            ->withTimestamps();
    }

    /**
     * Blocked when any predecessor hasn't been moved to done yet. Cheap
     * path is a single count query; callers that render many rows
     * should eager-load `predecessors` and use the collection form.
     */
    public function isBlocked(): bool
    {
        if ($this->relationLoaded('predecessors')) {
            return $this->predecessors->contains(fn ($p) => $p->state !== 'done');
        }

        return $this->predecessors()->where('state', '!=', 'done')->exists();
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /** @return HasMany<Task, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    protected function subjectsTable(): string
    {
        return 'task_subjects';
    }

    protected function subjectsForeignKey(): string
    {
        return 'task_id';
    }
}
