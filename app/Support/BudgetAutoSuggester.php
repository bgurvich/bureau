<?php

namespace App\Support;

use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Derives suggested monthly budget caps from the user's historical spend
 * per category.
 *
 * Strategy: the 75th percentile of last N months' monthly outflow per
 * category. Why 75P and not the mean:
 *   - means are pulled up by occasional big months (medical, travel)
 *     making caps too loose;
 *   - medians ignore the normal variance completely, making caps too tight;
 *   - 75P says "three months out of four this spend fit under the cap" —
 *     a useful ceiling the user actually notices when crossed.
 *
 * Categories with fewer than `minMonths` observed months are skipped: not
 * enough signal to suggest a number. Unknown cadence + seasonal categories
 * (taxes, holidays) will land here and stay manual.
 */
class BudgetAutoSuggester
{
    public function __construct(
        private readonly int $lookbackMonths = 6,
        private readonly int $minMonths = 3,
        private readonly float $percentile = 0.75,
    ) {}

    /**
     * @return Collection<int, object{category: Category, samples: int, p75: float, mean: float, existing: ?BudgetCap}>
     */
    public function suggestions(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $start = $now->subMonths($this->lookbackMonths)->startOfMonth();
        $end = $now->startOfMonth()->subSecond();   // last full month

        // Per-category, per-month outflow totals.
        $rows = Transaction::query()
            ->whereNotNull('category_id')
            ->where('amount', '<', 0)
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category_id, DATE_FORMAT(occurred_on, "%Y-%m") as ym, SUM(ABS(amount)) as total')
            ->groupBy('category_id', 'ym')
            ->get();

        $byCat = [];
        foreach ($rows as $r) {
            $byCat[(int) $r->getAttribute('category_id')][] = (float) $r->getAttribute('total');
        }

        $cats = Category::whereIn('id', array_keys($byCat))->get()->keyBy('id');
        $existing = BudgetCap::whereIn('category_id', array_keys($byCat))->get()->keyBy('category_id');

        $out = collect();
        foreach ($byCat as $catId => $samples) {
            if (count($samples) < $this->minMonths) {
                continue;
            }
            sort($samples);
            $mean = array_sum($samples) / count($samples);
            $p75 = self::quantile($samples, $this->percentile);

            $out->push((object) [
                'category' => $cats[$catId] ?? null,
                'samples' => count($samples),
                'p75' => $p75,
                'mean' => $mean,
                'existing' => $existing[$catId] ?? null,
            ]);
        }

        return $out->filter(fn ($s) => $s->category !== null)->sortByDesc('p75')->values();
    }

    /**
     * @param  array<int, float>  $sorted
     */
    private static function quantile(array $sorted, float $q): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $pos = $q * ($n - 1);
        $lower = (int) floor($pos);
        $upper = (int) ceil($pos);
        $frac = $pos - $lower;

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * $frac;
    }
}
