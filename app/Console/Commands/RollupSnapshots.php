<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AssetValuation;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Property;
use App\Models\Snapshot;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Builds the monthly net-worth + cashflow snapshots that feed the money radar
 * trend lines and future forecast surfaces. One rollup row per (household,
 * kind, taken_on); re-running is idempotent.
 *
 * Default target is the month that just closed (prior month), so the typical
 * run happens on the 2nd of each month (after `recurring:project`).
 */
class RollupSnapshots extends Command
{
    protected $signature = 'snapshots:rollup
        {--month= : YYYY-MM target; defaults to the prior month}
        {--household= : Only roll up this household id}';

    protected $description = 'Roll up monthly net-worth and cashflow snapshots into the snapshots table.';

    public function handle(): int
    {
        $monthOpt = $this->option('month');
        $target = $monthOpt
            ? CarbonImmutable::createFromFormat('Y-m', $monthOpt)->startOfMonth()
            : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        $monthStart = $target->toDateString();
        $monthEnd = $target->endOfMonth()->toDateString();

        $households = Household::query()
            ->when($this->option('household'), fn ($q) => $q->where('id', $this->option('household')))
            ->get();

        $netWorthCount = 0;
        $cashflowCount = 0;

        foreach ($households as $household) {
            CurrentHousehold::set($household);
            $netWorthCount += (int) $this->rollupNetWorth($household->id, $monthEnd);
            $cashflowCount += (int) $this->rollupCashflow($household->id, $monthStart, $monthEnd);
        }

        $this->info(sprintf(
            'Rolled up %d net-worth + %d cashflow snapshot(s) for %s across %d household(s).',
            $netWorthCount,
            $cashflowCount,
            $target->format('Y-m'),
            $households->count(),
        ));

        return self::SUCCESS;
    }

    private function rollupNetWorth(int $householdId, string $asOf): bool
    {
        $accountIds = Account::where('include_in_net_worth', true)->pluck('id');

        $accountTotal = 0.0;
        $byKind = [
            'bank' => 0.0, 'credit' => 0.0, 'cash' => 0.0, 'investment' => 0.0,
            'loan' => 0.0, 'mortgage' => 0.0,
            'property' => 0.0, 'vehicle' => 0.0, 'inventory' => 0.0,
        ];

        $txnSums = Transaction::whereIn('account_id', $accountIds)
            ->where('status', 'cleared')
            ->whereDate('occurred_on', '<=', $asOf)
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $transferOut = Transfer::whereIn('from_account_id', $accountIds)
            ->where('status', 'cleared')
            ->whereDate('occurred_on', '<=', $asOf)
            ->selectRaw('from_account_id, SUM(from_amount) as total')
            ->groupBy('from_account_id')
            ->pluck('total', 'from_account_id');

        $transferIn = Transfer::whereIn('to_account_id', $accountIds)
            ->where('status', 'cleared')
            ->whereDate('occurred_on', '<=', $asOf)
            ->selectRaw('to_account_id, SUM(to_amount) as total')
            ->groupBy('to_account_id')
            ->pluck('total', 'to_account_id');

        foreach (Account::whereIn('id', $accountIds)->get() as $account) {
            $bal = (float) $account->opening_balance
                + (float) ($txnSums[$account->id] ?? 0)
                - (float) ($transferOut[$account->id] ?? 0)
                + (float) ($transferIn[$account->id] ?? 0);
            $accountTotal += $bal;
            $byKind[$account->type] = ($byKind[$account->type] ?? 0) + $bal;
        }

        $assetsTotal = 0.0;
        foreach ([[Property::class, 'property'], [Vehicle::class, 'vehicle'], [InventoryItem::class, 'inventory']] as [$class, $bucket]) {
            $total = $this->assetsValueAt($class, $asOf);
            $byKind[$bucket] = $total;
            $assetsTotal += $total;
        }

        $this->upsertHouseholdSnapshot(
            householdId: $householdId,
            kind: 'net_worth',
            takenOn: $asOf,
            payload: [
                'total' => round($accountTotal + $assetsTotal, 2),
                'accounts' => round($accountTotal, 2),
                'assets' => round($assetsTotal, 2),
                'by_kind' => array_map(fn ($v) => round($v, 2), $byKind),
            ],
        );

        return true;
    }

    /**
     * Latest valuation per asset on or before $asOf, summed. For assets that
     * never had an explicit valuation, fall back to the purchase/cost price
     * so they still land somewhere on the net-worth line.
     *
     * @param  class-string  $class
     */
    private function assetsValueAt(string $class, string $asOf): float
    {
        $assets = $class::query()->get();
        if ($assets->isEmpty()) {
            return 0.0;
        }

        $latest = AssetValuation::where('valuable_type', $class)
            ->whereIn('valuable_id', $assets->pluck('id'))
            ->whereDate('as_of', '<=', $asOf)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get()
            ->unique('valuable_id')
            ->keyBy('valuable_id');

        $fallbackField = match ($class) {
            Property::class, Vehicle::class => 'purchase_price',
            InventoryItem::class => 'cost_amount',
            default => null,
        };

        $total = 0.0;
        foreach ($assets as $asset) {
            $valuation = $latest->get($asset->id);
            if ($valuation) {
                $total += (float) $valuation->value;

                continue;
            }
            if ($fallbackField && $asset->{$fallbackField} !== null) {
                $total += (float) $asset->{$fallbackField};
            }
        }

        return $total;
    }

    private function rollupCashflow(int $householdId, string $monthStart, string $monthEnd): bool
    {
        $txns = Transaction::whereBetween('occurred_on', [$monthStart, $monthEnd])
            ->where('status', 'cleared')
            ->get(['amount', 'category_id']);

        $income = 0.0;
        $expense = 0.0;
        $byCategory = [];

        $categoryNames = Category::whereIn('id', $txns->pluck('category_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($txns as $t) {
            $amount = (float) $t->amount;
            if ($amount >= 0) {
                $income += $amount;
            } else {
                $expense += abs($amount);
            }

            $key = $t->category_id ? (string) ($categoryNames[$t->category_id] ?? 'Uncategorized') : 'Uncategorized';
            $byCategory[$key] = round(($byCategory[$key] ?? 0) + $amount, 2);
        }

        $this->upsertHouseholdSnapshot(
            householdId: $householdId,
            kind: 'cashflow',
            takenOn: $monthStart,
            payload: [
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'net' => round($income - $expense, 2),
                'by_category' => $byCategory,
            ],
        );

        return true;
    }

    /**
     * Household-wide snapshots have null subject_type/subject_id; Laravel's
     * updateOrCreate can't match NULL via `=`, so do the lookup manually.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upsertHouseholdSnapshot(int $householdId, string $kind, string $takenOn, array $payload): void
    {
        $existing = Snapshot::where('household_id', $householdId)
            ->where('kind', $kind)
            ->whereNull('subject_type')
            ->whereNull('subject_id')
            ->whereDate('taken_on', $takenOn)
            ->first();

        $attributes = [
            'period' => 'monthly',
            'source' => 'scheduled',
            'payload' => $payload,
        ];

        if ($existing) {
            $existing->update($attributes);

            return;
        }

        Snapshot::create([
            'household_id' => $householdId,
            'kind' => $kind,
            'taken_on' => $takenOn,
            ...$attributes,
        ]);
    }
}
