<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    use BelongsToHousehold, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'occurred_on' => 'date',
        'from_amount' => 'decimal:4',
        'to_amount' => 'decimal:4',
        'fee_amount' => 'decimal:4',
    ];

    /** @return BelongsTo<Account, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    protected static function booted(): void
    {
        static::saving(fn (self $t) => PeriodLock::assertWritable($t->occurred_on));
        static::deleting(fn (self $t) => PeriodLock::assertWritable($t->occurred_on));
    }
}
