<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated journal entry (one per day is typical but not enforced —
 * the user can log multiple entries on the same day for big events).
 * Private by default; tags + subject-refs feed the review surfaces.
 */
class JournalEntry extends Model
{
    use BelongsToHousehold, HasMedia, HasSubjects, HasTags;

    protected $guarded = [];

    protected $casts = [
        'occurred_on' => 'date',
        'private' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function subjectsTable(): string
    {
        return 'journal_entry_subjects';
    }

    protected function subjectsForeignKey(): string
    {
        return 'journal_entry_id';
    }
}
