<?php

namespace App\Models\Concerns;

use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Gives any domain model the inverse side of the polymorphic task↔subject
 * relationship: `$property->tasks` returns every Task that lists this
 * property in its task_subjects pivot. Apply to any entity a user might
 * naturally say "this task is about THIS thing" about.
 */
trait HasLinkedTasks
{
    /**
     * @return MorphToMany<Task, $this>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'subject', 'task_subjects');
    }
}
