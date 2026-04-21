<?php

namespace App\Models\Concerns;

use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Gives any domain model the inverse side of the polymorphic note↔subject
 * relationship: `$vehicle->notes` returns every Note that lists this
 * vehicle in its note_subjects pivot. Symmetric with HasLinkedTasks.
 */
trait HasLinkedNotes
{
    /**
     * @return MorphToMany<Note, $this>
     */
    public function notes(): MorphToMany
    {
        return $this->morphToMany(Note::class, 'subject', 'note_subjects');
    }
}
