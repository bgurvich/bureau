<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit-trail row for portal sessions. Create via PortalActivityLog
 * (no constructors here); this model is read-mostly for the owner's
 * grants-manager view.
 */
class PortalActivityEvent extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<PortalGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(PortalGrant::class, 'portal_grant_id');
    }
}
