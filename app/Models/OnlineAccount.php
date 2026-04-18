<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineAccount extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'in_case_of_pack' => 'boolean',
    ];

    /** @return BelongsTo<Contact, $this> */
    public function recoveryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recovery_contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function linkedContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'linked_contract_id');
    }
}
