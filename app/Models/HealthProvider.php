<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthProvider extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'provider_id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'prescriber_id');
    }
}
