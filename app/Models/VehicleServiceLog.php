<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per maintenance event on a Vehicle. Receipts/shop invoices
 * attach via HasMedia so the Mail inbox flow can drop the scanned
 * invoice straight onto the record.
 */
class VehicleServiceLog extends Model
{
    use HasMedia, HasTags;

    protected $table = 'vehicle_service_log';

    protected $guarded = [];

    protected $casts = [
        'service_date' => 'date',
        'odometer' => 'integer',
        'cost' => 'decimal:2',
    ];

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function providerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'provider_contact_id');
    }
}
