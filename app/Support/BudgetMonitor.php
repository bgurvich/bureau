<?php

namespace App\Support;

use App\Models\BudgetCap;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregates current-month outflows per category and compares against the
 * user's budget caps. A "status row" for each active cap contains the cap
 * amount, month-to-date spend, utilization ratio, and whether the envelope
 * is already over. The radar + a future /budgets page both read this.
 */
class BudgetMonitor
{
    public const OVER = 'over';

    public const WARNING = 'warning';

    public const OK = 'ok';

    /**
     * @return Collection<int, BudgetStatus>
     */
    public static function currentMonthStatuses(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $start = $now->startOfMonth();
        $end = $now->endOfMonth();

        /** @var Collection<int, BudgetCap> $caps */
        $caps = BudgetCap::with('category:id,name')
            ->where('active', true)
            ->get();

        if ($caps->isEmpty()) {
            /** @var Collection<int, BudgetStatus> $empty */
            $empty = collect();

            return $empty;
        }

        // One sum query per capped category. N caps = N queries — trivial at
        // the household scale Secretaire targets. If this grows, replace with a
        // single GROUP BY on a JOIN.
        return $caps->map(function (BudgetCap $cap) use ($start, $end): BudgetStatus {
            $spent = (float) abs((float) Transaction::query()
                ->where('category_id', $cap->category_id)
                ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
                ->where('amount', '<', 0)
                ->sum('amount'));
            $cap_amount = (float) $cap->monthly_cap;
            $ratio = $cap_amount > 0 ? $spent / $cap_amount : 0.0;
            $state = match (true) {
                $ratio >= 1.0 => self::OVER,
                $ratio >= 0.8 => self::WARNING,
                default => self::OK,
            };

            return new BudgetStatus(cap: $cap, spent: $spent, ratio: $ratio, state: $state);
        });
    }

    /**
     * How many envelopes are already ≥80% used this month — the number the
     * attention radar wants to show as a single tile.
     */
    public static function currentMonthWarningCount(?CarbonImmutable $now = null): int
    {
        return self::currentMonthStatuses($now)
            ->filter(fn ($s) => in_array($s->state, [self::WARNING, self::OVER], true))
            ->count();
    }
}
