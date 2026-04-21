<?php

namespace App\Jobs;

use App\Models\Media;
use App\Support\CurrentHousehold;
use App\Support\ReceiptExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractOcrStructure implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $mediaId) {}

    public function handle(ReceiptExtractor $extractor): void
    {
        if (! config('services.lm_studio.enabled')) {
            return;
        }

        $m = Media::withoutGlobalScopes()->find($this->mediaId);
        if (! $m) {
            return;
        }

        $household = $m->household;
        if ($household) {
            CurrentHousehold::set($household);
        }

        $text = (string) ($m->ocr_text ?? '');
        if (trim($text) === '') {
            $m->forceFill(['extraction_status' => 'skipped'])->save();

            return;
        }

        try {
            $extracted = $extractor->extract($text);
        } catch (\Throwable $e) {
            $meta = $m->meta ?? [];
            $meta['extraction_error'] = $e->getMessage();
            $m->forceFill(['extraction_status' => 'failed', 'meta' => $meta])->save();

            return;
        }

        if ($extracted === null) {
            $m->forceFill(['extraction_status' => 'failed'])->save();

            return;
        }

        $m->forceFill([
            'extraction_status' => 'done',
            'ocr_extracted' => $extracted,
        ])->save();
    }
}
