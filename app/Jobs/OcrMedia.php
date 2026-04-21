<?php

namespace App\Jobs;

use App\Models\Media;
use App\Support\CurrentHousehold;
use App\Support\Ocr;
use App\Support\Pdf;
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

        $mime = (string) $m->mime;
        $isImage = str_starts_with($mime, 'image/');
        $isPdf = $mime === 'application/pdf';
        if (! $isImage && ! $isPdf) {
            $m->forceFill(['ocr_status' => 'skip'])->save();

            return;
        }

        $absolutePath = Storage::disk($m->disk ?: 'local')->path($m->path);

        try {
            $text = $isPdf ? Pdf::extractText($absolutePath) : Ocr::extract($absolutePath);
            if ($text === null || trim((string) $text) === '') {
                $m->forceFill(['ocr_status' => 'failed'])->save();

                return;
            }
            $fields = [
                'ocr_status' => 'done',
                'ocr_text' => $text,
            ];
            if (config('services.lm_studio.enabled')) {
                $fields['extraction_status'] = 'pending';
            }
            $m->forceFill($fields)->save();

            if (config('services.lm_studio.enabled')) {
                ExtractOcrStructure::dispatch($m->id);
            }
        } catch (\Throwable $e) {
            $meta = $m->meta ?? [];
            $meta['ocr_error'] = $e->getMessage();
            $m->forceFill(['ocr_status' => 'failed', 'meta' => $meta])->save();
        }
    }
}
