<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasTags;

    protected $guarded = [];

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
