<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasSubjects, HasTags;

    protected $guarded = [];

    protected function subjectsTable(): string
    {
        return 'project_subjects';
    }

    protected function subjectsForeignKey(): string
    {
        return 'project_id';
    }

    protected $casts = [
        'billable' => 'boolean',
        'hourly_rate' => 'decimal:4',
        'archived' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_contact_id');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function totalSeconds(): int
    {
        return (int) $this->entries()->sum('duration_seconds');
    }
}
