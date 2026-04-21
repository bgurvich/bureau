<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\HasValuations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasMedia, HasTags, HasValuations;

    protected $guarded = [];

    protected $casts = [
        'opening_balance' => 'decimal:4',
        'data' => 'array',
        'opened_on' => 'date',
        'closed_on' => 'date',
        'expires_on' => 'date',
        'is_active' => 'boolean',
        'include_in_net_worth' => 'boolean',
    ];

    /** @return BelongsTo<Contact, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'counterparty_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'vendor_contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<LoanTerm, $this> */
    public function loanTerms(): HasOne
    {
        return $this->hasOne(LoanTerm::class);
    }

    /** @return HasMany<AccountBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    /** @return HasOne<AccountBalance, $this> */
    public function latestBalance(): HasOne
    {
        return $this->hasOne(AccountBalance::class)->latestOfMany('as_of');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Transfer, $this> */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_account_id');
    }

    /** @return HasMany<Transfer, $this> */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_account_id');
    }
}
