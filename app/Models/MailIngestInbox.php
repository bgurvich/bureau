<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailIngestInbox extends Model
{
    use BelongsToHousehold;

    protected $table = 'mail_ingest_inbox';

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'inbox_id');
    }
}
