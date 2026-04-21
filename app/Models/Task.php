<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

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
