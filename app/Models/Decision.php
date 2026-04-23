<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision record. Subjects (optional) let the user link the call
 * to the things it's about — the Civic being replaced, the contract
 * being cancelled, the pet changing vets — so the decision shows up
 * from those records' linked-history views.
 */
class Decision extends Model
{
    use BelongsToHousehold, HasSubjects, HasTags;

    protected $guarded = [];

    protected $casts = [
        'decided_on' => 'date',
        'follow_up_on' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function subjectsTable(): string
    {
        return 'decision_subjects';
    }

    protected function subjectsForeignKey(): string
    {
        return 'decision_id';
    }
}
