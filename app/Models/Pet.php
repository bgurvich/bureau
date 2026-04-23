<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pet extends Model
{
    use BelongsToHousehold, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function primaryOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_owner_user_id');
    }

    /** @return BelongsTo<HealthProvider, $this> */
    public function vetProvider(): BelongsTo
    {
        return $this->belongsTo(HealthProvider::class, 'vet_provider_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }

    /** @return HasMany<PetVaccination, $this> */
    public function vaccinations(): HasMany
    {
        return $this->hasMany(PetVaccination::class);
    }

    /** @return HasMany<PetCheckup, $this> */
    public function checkups(): HasMany
    {
        return $this->hasMany(PetCheckup::class);
    }

    /** @return HasMany<PetLicense, $this> */
    public function licenses(): HasMany
    {
        return $this->hasMany(PetLicense::class);
    }

    /** @return MorphMany<Prescription, $this> */
    public function prescriptions(): MorphMany
    {
        return $this->morphMany(Prescription::class, 'subject');
    }
}
