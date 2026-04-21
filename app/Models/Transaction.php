<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use BelongsToHousehold, HasMedia, HasSubjects, HasTags;

    protected $guarded = [];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'decimal:4',
        'closing_balance' => 'decimal:4',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'counterparty_contact_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function fundedBy(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'funded_by_transaction_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function fundedChildren(): HasMany
    {
        return $this->hasMany(Transaction::class, 'funded_by_transaction_id');
    }

    protected function subjectsTable(): string
    {
        return 'transaction_subjects';
    }

    protected function subjectsForeignKey(): string
    {
        return 'transaction_id';
    }

    protected static function booted(): void
    {
        static::saving(fn (self $t) => PeriodLock::assertWritable($t->occurred_on));
        static::deleting(fn (self $t) => PeriodLock::assertWritable($t->occurred_on));
    }
}
