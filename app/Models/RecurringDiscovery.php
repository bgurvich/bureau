<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringDiscovery extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'median_amount' => 'decimal:4',
        'amount_variance' => 'decimal:4',
        'occurrence_count' => 'integer',
        'first_seen_on' => 'date',
        'last_seen_on' => 'date',
        'score' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'counterparty_contact_id');
    }
}
