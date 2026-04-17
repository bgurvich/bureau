<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAttachment extends Model
{
    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'message_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
