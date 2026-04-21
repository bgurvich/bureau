<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

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

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<MailIngestInbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(MailIngestInbox::class, 'inbox_id');
    }

    /** @return HasMany<MailAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class, 'message_id');
    }

    /**
     * Records produced from this email — derived via the existing
     * attachments → media → mediables chain. No new pivot required because
     * HTML-body bills get synthetic Media rows, so every mail-sourced
     * record lives on mediables with role=receipt.
     *
     * @return Collection<int, Model>
     */
    public function processedRecords(): Collection
    {
        $mediaIds = $this->attachments()
            ->whereNotNull('media_id')
            ->pluck('media_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($mediaIds === []) {
            return new Collection;
        }

        $rows = DB::table('mediables')
            ->whereIn('media_id', $mediaIds)
            ->where('role', 'receipt')
            ->get(['mediable_type', 'mediable_id']);

        /** @var Collection<int, Model> $out */
        $out = new Collection;
        foreach ($rows->groupBy('mediable_type') as $type => $group) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;
            if (! class_exists($class)) {
                continue;
            }
            /** @var class-string<Model> $class */
            $loaded = $class::query()
                ->whereIn('id', $group->pluck('mediable_id')->map(fn ($v) => (int) $v)->all())
                ->get();
            foreach ($loaded as $row) {
                $out->push($row);
            }
        }

        return $out;
    }

    /**
     * Flip processed_at when every attached Media has been processed.
     * Called after a Media's processed_at is set (see Inspector auto-mark
     * and media dismissProcessing).
     */
    public function markProcessedIfComplete(): void
    {
        if ($this->processed_at !== null) {
            return;
        }

        $mediaIds = $this->attachments()
            ->whereNotNull('media_id')
            ->pluck('media_id');
        if ($mediaIds->isEmpty()) {
            return;
        }

        $totalLinked = $mediaIds->count();
        $processed = Media::whereIn('id', $mediaIds)
            ->whereNotNull('processed_at')
            ->count();

        if ($processed === $totalLinked) {
            $this->forceFill(['processed_at' => now()])->save();
        }
    }

    /**
     * Walk back from a Media id to any MailMessages that referenced it via
     * mail_attachments, and close out each that has no unprocessed media
     * left. Callers invoke this right after flipping Media.processed_at.
     */
    public static function cascadeProcessedFromMedia(int $mediaId): void
    {
        $messageIds = MailAttachment::query()
            ->where('media_id', $mediaId)
            ->pluck('message_id')
            ->unique();

        foreach ($messageIds as $id) {
            $msg = static::withoutGlobalScopes()->find($id);
            $msg?->markProcessedIfComplete();
        }
    }
}
