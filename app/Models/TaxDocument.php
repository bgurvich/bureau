<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single tax document sitting under a TaxYear — W-2, 1099 flavours,
 * K-1, receipts, schedules. The scan itself attaches via HasMedia's
 * morphed pivot so the Mail inbox flow can drop a PDF straight onto a
 * row.
 */
class TaxDocument extends Model
{
    use HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'received_on' => 'date',
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<TaxYear, $this> */
    public function taxYear(): BelongsTo
    {
        return $this->belongsTo(TaxYear::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function fromContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'from_contact_id');
    }
}
