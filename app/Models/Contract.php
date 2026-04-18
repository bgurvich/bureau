<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    use BelongsToHousehold, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'trial_ends_on' => 'date',
        'auto_renews' => 'boolean',
        'monthly_cost_amount' => 'decimal:4',
    ];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->withPivot('party_role')->withTimestamps();
    }

    public function insurancePolicy(): HasOne
    {
        return $this->hasOne(InsurancePolicy::class);
    }
}
