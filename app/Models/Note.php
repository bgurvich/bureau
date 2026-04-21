<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSubjects;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    use BelongsToHousehold, HasMedia, HasSubjects, HasTags;

    protected $guarded = [];

    protected $casts = [
        'pinned' => 'boolean',
        'private' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function subjectsTable(): string
    {
        return 'note_subjects';
    }

    protected function subjectsForeignKey(): string
    {
        return 'note_id';
    }
}
