<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateMediaThumbnail;
use App\Models\Media;
use Illuminate\Console\Command;

/**
 * Re-queue PDF thumbnail generation for existing media rows. Two flavors:
 *   --missing  (default) — only PDFs without a thumb_path, i.e. first-run backfill
 *                          after install-packages.sh lands poppler, or rescue
 *                          for prior runs where pdftoppm wasn't available yet.
 *   --all                — every PDF, regardless of thumb_path. Useful when
 *                          the pdftoppm args change or you want to bump the
 *                          output size across the archive.
 *
 * The job is idempotent — it overwrites thumbs/<media-id>.png atomically — so
 * re-running is safe even while workers are mid-flight on older dispatches.
 */
class RegenerateMediaThumbnails extends Command
{
    protected $signature = 'media:thumbnails
        {--all : Regenerate every PDF thumbnail, not just missing ones}
        {--household= : Limit to a single household id}
        {--dry-run : Count matches without queuing jobs}';

    protected $description = 'Queue PDF thumbnail regeneration for media rows (default: missing only).';

    public function handle(): int
    {
        $query = Media::query()
            ->withoutGlobalScopes()
            ->where('mime', 'application/pdf');

        if (! $this->option('all')) {
            $query->whereNull('thumb_path');
        }
        if ($household = $this->option('household')) {
            $query->where('household_id', (int) $household);
        }

        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("dry-run: {$count} PDF(s) would be queued.");

            return self::SUCCESS;
        }
        if ($count === 0) {
            $this->info('Nothing to queue.');

            return self::SUCCESS;
        }

        $this->info("Queuing {$count} thumbnail job(s)…");
        $dispatched = 0;
        $query->select('id')->chunkById(200, function ($rows) use (&$dispatched) {
            foreach ($rows as $row) {
                GenerateMediaThumbnail::dispatch($row->id);
                $dispatched++;
            }
        });
        $this->info("Dispatched {$dispatched} job(s).");

        return self::SUCCESS;
    }
}
