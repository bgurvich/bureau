<?php

namespace App\Support;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Flags transactions whose magnitude is an outlier vs the 90-day category
 * baseline. "Outlier" = more than `sigmaThreshold` standard deviations
 * above the mean absolute amount for the transaction's category. We
 * intentionally compare on absolute value because the user cares about
 * "why was this spend unusually big" — sign is already captured by the
 * category's ledger convention.
 *
 * The baseline needs at least `minSamples` prior transactions in the
 * category to avoid flagging early occurrences as anomalies. Below that,
 * we return `null` (no verdict) rather than a false positive.
 *
 * Returns anomalies for recent (trailing 7d) transactions only — the
 * radar tile is about "something unusual just happened", not "rescan the
 * whole year every render".
 */
class SpendingAnomalyDetector
{
    public function __construct(
        private readonly int $baselineDays = 90,
        private readonly int $recentDays = 7,
        private readonly int $minSamples = 5,
        private readonly float $sigmaThreshold = 2.5,
    ) {}

    /**
     * @return Collection<int, array{transaction: Transaction, mean: float, stddev: float, zscore: float}>
     */
    public function recentAnomalies(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $recentFrom = $now->subDays($this->recentDays)->startOfDay()->toDateString();
        $baselineFrom = $now->subDays($this->baselineDays)->startOfDay()->toDateString();

        $recent = Transaction::query()
            ->whereNotNull('category_id')
            ->whereBetween('occurred_on', [$recentFrom, $now->toDateString()])
            ->where('amount', '<', 0)
            ->get(['id', 'category_id', 'amount', 'occurred_on', 'description']);

        if ($recent->isEmpty()) {
            /** @var Collection<int, array{transaction: Transaction, mean: float, stddev: float, zscore: float}> $empty */
            $empty = collect();

            return $empty;
        }

        // Build per-category stats from the baseline window, excluding the
        // transactions we're evaluating (otherwise a single huge outlier
        // pulls up its own mean and hides itself).
        $baseline = Transaction::query()
            ->whereNotNull('category_id')
            ->whereBetween('occurred_on', [$baselineFrom, $recentFrom])
            ->where('amount', '<', 0)
            ->get(['category_id', 'amount'])
            ->groupBy('category_id');

        $anomalies = collect();
        foreach ($recent as $t) {
            $sample = $baseline->get($t->category_id) ?? collect();
            if ($sample->count() < $this->minSamples) {
                continue;
            }
            $values = $sample->map(fn ($r) => abs((float) $r->amount))->all();
            $mean = array_sum($values) / count($values);
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values);
            $stddev = sqrt($variance);
            if ($stddev <= 0.0001) {
                // Baseline is flat (all identical amounts) — require a
                // strictly larger magnitude to flag.
                if (abs((float) $t->amount) > $mean) {
                    $anomalies->push([
                        'transaction' => $t,
                        'mean' => $mean,
                        'stddev' => $stddev,
                        'zscore' => INF,
                    ]);
                }

                continue;
            }
            $z = (abs((float) $t->amount) - $mean) / $stddev;
            if ($z >= $this->sigmaThreshold) {
                $anomalies->push([
                    'transaction' => $t,
                    'mean' => $mean,
                    'stddev' => $stddev,
                    'zscore' => $z,
                ]);
            }
        }

        /** @var Collection<int, array{transaction: Transaction, mean: float, stddev: float, zscore: float}> $anomalies */
        return $anomalies->sortByDesc('zscore')->values();
    }

    public function recentAnomaliesCount(?CarbonImmutable $now = null): int
    {
        return $this->recentAnomalies($now)->count();
    }
}
