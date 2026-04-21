<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of physical mail received — a letter, bill, slip, medical
 * form, etc. Photos attach through HasMedia (same mediables pivot the
 * rest of the app uses). Linked tasks + notes let the user spawn a
 * followup ("reply by Tuesday") or annotate context without cluttering
 * the summary field.
 */
class PhysicalMail extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasMedia;

    protected $table = 'physical_mail';

    protected $guarded = [];

    protected $casts = [
        'received_on' => 'date',
        'processed_at' => 'datetime',
        'action_required' => 'boolean',
    ];

    /** @return BelongsTo<Contact, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_contact_id');
    }
}
