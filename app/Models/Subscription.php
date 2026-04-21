<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'monthly_cost_cached' => 'decimal:4',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'paused_until' => 'date',
    ];

    /** @return BelongsTo<Contact, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'counterparty_contact_id');
    }

    /** @return BelongsTo<RecurringRule, $this> */
    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
