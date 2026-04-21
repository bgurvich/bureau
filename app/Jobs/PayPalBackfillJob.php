<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Integration;
use App\Support\PayPal\PayPalSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Run a PayPal historical sync in the background. The settings-page
 * "Backfill…" modal dispatches this so a multi-year pull (which can
 * chunk through dozens of 30-day Reporting API windows) doesn't
 * block the HTTP request cycle. Cursor still advances to "now" inside
 * PayPalSync::sync so the normal incremental schedule resumes
 * cleanly after this finishes.
 */
class PayPalBackfillJob implements ShouldQueue
{
    use FoundationQueueable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $fromDate,
    ) {}

    public function handle(PayPalSync $sync): void
    {
        $integration = Integration::find($this->integrationId);
        if (! $integration || $integration->provider !== 'paypal') {
            Log::warning('PayPalBackfillJob: integration not found or not paypal', [
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        $from = CarbonImmutable::parse($this->fromDate)->startOfDay();
        $created = $sync->sync($integration, $from);

        Log::info('PayPalBackfillJob done', [
            'integration_id' => $integration->id,
            'from' => $from->toDateString(),
            'created' => $created,
        ]);
    }

    // PayPal's Reporting API rate-limits bursts; don't hammer it with
    // retries on transient errors.
    public int $tries = 2;

    public int $timeout = 1800; // 30 min — a full 3-year backfill fits comfortably
}
