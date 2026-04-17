<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecurringRule extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:4',
        'dtstart' => 'date',
        'until' => 'date',
        'active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'counterparty_contact_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function projections(): HasMany
    {
        return $this->hasMany(RecurringProjection::class, 'rule_id');
    }
}
