<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use BelongsToHousehold, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'decimal:4',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'counterparty_contact_id');
    }

    protected static function booted(): void
    {
        static::saving(fn (self $t) => PeriodLock::assertWritable($t->occurred_on));
        static::deleting(fn (self $t) => PeriodLock::assertWritable($t->occurred_on));
    }
}
