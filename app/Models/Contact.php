<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'phones' => 'array',
        'emails' => 'array',
        'addresses' => 'array',
        'favorite' => 'boolean',
        'is_vendor' => 'boolean',
        'is_customer' => 'boolean',
    ];

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }

    /** @return BelongsToMany<Contract, $this> */
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class)->withPivot('party_role')->withTimestamps();
    }

    /** @return BelongsToMany<Meeting, $this> */
    public function meetings(): BelongsToMany
    {
        return $this->belongsToMany(Meeting::class, 'meeting_contact')
            ->withPivot('role', 'rsvp')
            ->withTimestamps();
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'counterparty_contact_id');
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'counterparty_contact_id');
    }
}
