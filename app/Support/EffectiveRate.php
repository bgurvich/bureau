<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Transfer;
use Carbon\CarbonImmutable;

/**
 * Derive the effective monthly interest rate on an account from its actual
 * interest-category transactions + average daily balance. Projects the
 * monthly rate to APR (12×rate) and APY ((1+rate)^12 − 1).
 *
 * Prerequisites:
 *   - A "interest-paid" (expense) or "interest-earned" (income) category
 *     exists for the household (seeded by migration).
 *   - The account has at least one full month of balance + interest history.
 */
class EffectiveRate
{
    /**
     * @return array{
     *     months_evaluated: int,
     *     average_monthly_rate: float,
     *     apr: float,
     *     apy: float,
     *     monthly: array<int, array{month: string, rate: float, interest: float, avg_balance: float}>
     * }|null
     */
    public static function forAccount(Account $account, int $monthsBack = 3): ?array
    {
        $side = match ($account->type) {
            'credit', 'loan', 'mortgage' => 'interest-paid',
            'checking', 'savings', 'cash', 'investment' => 'interest-earned',
            default => null,
        };

        if (! $side) {
            return null;
        }

        $categoryId = Category::where('slug', $side)->value('id');
        if (! $categoryId) {
            return null;
        }

        $months = [];
        $today = CarbonImmutable::today();

        for ($i = 1; $i <= $monthsBack; $i++) {
            $monthStart = $today->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->endOfMonth();

            $interest = (float) Transaction::where('account_id', $account->id)
                ->where('category_id', $categoryId)
                ->where('status', 'cleared')
                ->whereBetween('occurred_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('amount');
            $interest = abs($interest);

            if ($interest <= 0.0) {
                continue;
            }

            $avgBalance = self::averageDailyBalance($account, $monthStart, $monthEnd);
            if ($avgBalance <= 0.0) {
                continue;
            }

            $rate = $interest / $avgBalance;
            $months[] = [
                'month' => $monthStart->format('Y-m'),
                'rate' => $rate,
                'interest' => round($interest, 2),
                'avg_balance' => round($avgBalance, 2),
            ];
        }

        if (empty($months)) {
            return null;
        }

        $avgRate = array_sum(array_column($months, 'rate')) / count($months);

        return [
            'months_evaluated' => count($months),
            'average_monthly_rate' => $avgRate,
            'apr' => $avgRate * 12,
            'apy' => (1 + $avgRate) ** 12 - 1,
            'monthly' => $months,
        ];
    }

    /**
     * Average daily balance over [start, end] (inclusive). Balance each day =
     * opening_balance + cumulative cleared transactions + transfers through
     * that day. Uses absolute-value convention so credit/loan balances come
     * out positive for rate math.
     */
    private static function averageDailyBalance(Account $account, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $txnRows = Transaction::where('account_id', $account->id)
            ->where('status', 'cleared')
            ->whereDate('occurred_on', '<=', $end->toDateString())
            ->selectRaw('occurred_on, SUM(amount) as total')
            ->groupBy('occurred_on')
            ->pluck('total', 'occurred_on')
            ->map(fn ($v) => (float) $v)
            ->all();

        $outRows = Transfer::where('from_account_id', $account->id)
            ->where('status', 'cleared')
            ->whereDate('occurred_on', '<=', $end->toDateString())
            ->selectRaw('occurred_on, SUM(from_amount) as total')
            ->groupBy('occurred_on')
            ->pluck('total', 'occurred_on')
            ->map(fn ($v) => (float) $v)
            ->all();

        $inRows = Transfer::where('to_account_id', $account->id)
            ->where('status', 'cleared')
            ->whereDate('occurred_on', '<=', $end->toDateString())
            ->selectRaw('occurred_on, SUM(to_amount) as total')
            ->groupBy('occurred_on')
            ->pluck('total', 'occurred_on')
            ->map(fn ($v) => (float) $v)
            ->all();

        $opening = (float) $account->opening_balance;

        $cursor = $start;
        $sum = 0.0;
        $days = 0;
        $runningBalance = $opening;

        // Seed the running balance with everything up to (but not including) the window start.
        $preStart = $start->subDay();
        foreach ($txnRows as $date => $total) {
            if (CarbonImmutable::parse($date)->lte($preStart)) {
                $runningBalance += $total;
            }
        }
        foreach ($outRows as $date => $total) {
            if (CarbonImmutable::parse($date)->lte($preStart)) {
                $runningBalance -= $total;
            }
        }
        foreach ($inRows as $date => $total) {
            if (CarbonImmutable::parse($date)->lte($preStart)) {
                $runningBalance += $total;
            }
        }

        while ($cursor->lte($end)) {
            $dayKey = $cursor->toDateString();
            $runningBalance += (float) ($txnRows[$dayKey] ?? 0);
            $runningBalance -= (float) ($outRows[$dayKey] ?? 0);
            $runningBalance += (float) ($inRows[$dayKey] ?? 0);

            $sum += abs($runningBalance);
            $days++;
            $cursor = $cursor->addDay();
        }

        return $days > 0 ? $sum / $days : 0.0;
    }
}
