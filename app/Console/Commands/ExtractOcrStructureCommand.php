<?php

namespace App\Console\Commands;

use App\Jobs\ExtractOcrStructure;
use App\Models\Media;
use Illuminate\Console\Command;

class ExtractOcrStructureCommand extends Command
{
    protected $signature = 'ocr:extract-structure
        {--media-id= : Operate on a single Media row}
        {--limit=100 : Max rows to queue in one run}
        {--requeue-failed : Also pick up rows whose previous extraction failed}
        {--dry-run : Report what would be dispatched without queueing}';

    protected $description = 'Queue ExtractOcrStructure for Media rows that have OCR text but no LLM extraction yet.';

    public function handle(): int
    {
        if (! config('services.lm_studio.enabled')) {
            $this->warn('LM_STUDIO_ENABLED=false — nothing to do.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $mediaId = $this->option('media-id');

        $query = Media::withoutGlobalScopes()
            ->where('ocr_status', 'done')
            ->whereNotNull('ocr_text');

        if ($mediaId !== null) {
            $query->whereKey((int) $mediaId);
        } else {
            $statuses = $this->option('requeue-failed')
                ? [null, 'failed']
                : [null];
            $query->where(function ($q) use ($statuses) {
                $q->whereNull('extraction_status');
                foreach ($statuses as $status) {
                    if ($status !== null) {
                        $q->orWhere('extraction_status', $status);
                    }
                }
            });
            $query->orderBy('id')->limit($limit);
        }

        $dispatched = 0;
        foreach ($query->get(['id']) as $row) {
            if ($this->option('dry-run')) {
                $this->line("  would queue media #{$row->id}");
                $dispatched++;

                continue;
            }
            ExtractOcrStructure::dispatch((int) $row->id);
            $dispatched++;
        }

        $this->info("  Dispatched {$dispatched} ExtractOcrStructure job(s).");

        return self::SUCCESS;
    }
}
