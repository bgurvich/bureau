<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMessage extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'to_addresses' => 'array',
        'headers' => 'array',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(MailIngestInbox::class, 'inbox_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class, 'message_id');
    }
}
