<?php

namespace App\Jobs;

use App\Models\Media;
use App\Support\CurrentHousehold;
use App\Support\Ocr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class OcrMedia implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $mediaId) {}

    public function handle(): void
    {
        // Media's BelongsToHousehold scope needs a current household on the
        // queue worker; set it from the row itself to keep Media::find working.
        $m = Media::withoutGlobalScopes()->find($this->mediaId);
        if (! $m || $m->ocr_status !== 'pending') {
            return;
        }

        $household = $m->household;
        if ($household) {
            CurrentHousehold::set($household);
        }

        // Only images today. PDFs need pdftotext/poppler — out of scope for v1.
        if (! str_starts_with((string) $m->mime, 'image/')) {
            $m->forceFill(['ocr_status' => 'skip'])->save();

            return;
        }

        $absolutePath = Storage::disk($m->disk ?: 'local')->path($m->path);

        try {
            $text = Ocr::extract($absolutePath);
            if ($text === null) {
                $m->forceFill(['ocr_status' => 'failed'])->save();

                return;
            }
            $m->forceFill([
                'ocr_status' => 'done',
                'ocr_text' => $text,
            ])->save();
        } catch (\Throwable $e) {
            $meta = $m->meta ?? [];
            $meta['ocr_error'] = $e->getMessage();
            $m->forceFill(['ocr_status' => 'failed', 'meta' => $meta])->save();
        }
    }
}
