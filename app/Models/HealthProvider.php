<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthProvider extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions;

    protected $guarded = [];

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'provider_id');
    }

    /** @return HasMany<Prescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'prescriber_id');
    }
}
