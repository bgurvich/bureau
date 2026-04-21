<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasLinkedNotes;
use App\Models\Concerns\HasLinkedTasks;
use App\Models\Concerns\HasLinkedTransactions;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use BelongsToHousehold, HasLinkedNotes, HasLinkedTasks, HasLinkedTransactions, HasMedia, HasTags;

    protected $guarded = [];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'in_case_of_pack' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }
}
