<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\ReceiptMatcher;
use Illuminate\Console\Command;

/**
 * Sweeps unprocessed media rows with OCR extraction and attempts to pair
 * each with a matching outflow Transaction. Safe to run repeatedly —
 * already-processed media are skipped by the `processed_at` guard.
 */
class MatchReceiptsCommand extends Command
{
    protected $signature = 'receipts:match
                            {--tolerance=3 : Days of leeway around the receipt date}
                            {--limit=200 : Max receipts to process this run}';

    protected $description = 'Pair OCR-extracted receipt scans with outflow transactions';

    public function handle(ReceiptMatcher $matcher): int
    {
        $tolerance = max(0, (int) $this->option('tolerance'));
        $limit = max(1, (int) $this->option('limit'));
        $matcher = new ReceiptMatcher($tolerance);

        $counts = [ReceiptMatcher::MATCH_SINGLE => 0, ReceiptMatcher::MATCH_AMBIGUOUS => 0, ReceiptMatcher::MATCH_NONE => 0, ReceiptMatcher::MATCH_SKIP => 0];

        Media::whereNull('processed_at')
            ->where('extraction_status', 'done')
            ->whereNotNull('ocr_extracted')
            ->limit($limit)
            ->get()
            ->each(function (Media $m) use ($matcher, &$counts) {
                $counts[$matcher->match($m)]++;
            });

        $this->line(sprintf(
            'matched=%d ambiguous=%d no-match=%d skipped=%d',
            $counts[ReceiptMatcher::MATCH_SINGLE],
            $counts[ReceiptMatcher::MATCH_AMBIGUOUS],
            $counts[ReceiptMatcher::MATCH_NONE],
            $counts[ReceiptMatcher::MATCH_SKIP],
        ));

        return self::SUCCESS;
    }
}
