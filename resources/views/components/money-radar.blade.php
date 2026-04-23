<?php

use App\Models\Account;
use App\Models\AssetValuation;
use App\Models\InventoryItem;
use App\Models\Property;
use App\Models\RecurringProjection;
use App\Models\Snapshot;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function netWorth(): float
    {
        $accountIds = Account::where('is_active', true)
            ->where('include_in_net_worth', true)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->pluck('id');

        if ($accountIds->isEmpty() && ! $this->assetsPresent()) {
            return 0.0;
        }

        $txnSum = (float) Transaction::whereIn('account_id', $accountIds)
            ->where('status', 'cleared')
            ->sum('amount');

        $transferOut = (float) Transfer::whereIn('from_account_id', $accountIds)
            ->where('status', 'cleared')
            ->sum('from_amount');

        $transferIn = (float) Transfer::whereIn('to_account_id', $accountIds)
            ->where('status', 'cleared')
            ->sum('to_amount');

        $opening = (float) Account::whereIn('id', $accountIds)->sum('opening_balance');

        return $opening + $txnSum - $transferOut + $transferIn + $this->assetsValue();
    }

    private function assetsPresent(): bool
    {
        return Property::query()->exists()
            || Vehicle::query()->exists()
            || InventoryItem::query()->exists();
    }

    private function assetsValue(): float
    {
        $total = 0.0;
        foreach ([[Property::class, 'purchase_price'], [Vehicle::class, 'purchase_price'], [InventoryItem::class, 'cost_amount']] as [$class, $fallback]) {
            $assets = $class::query()->get();
            if ($assets->isEmpty()) {
                continue;
            }

            $latest = AssetValuation::where('valuable_type', $class)
                ->whereIn('valuable_id', $assets->pluck('id'))
                ->orderByDesc('as_of')
                ->orderByDesc('id')
                ->get()
                ->unique('valuable_id')
                ->keyBy('valuable_id');

            foreach ($assets as $asset) {
                $v = $latest->get($asset->id);
                if ($v) {
                    $total += (float) $v->value;
                } elseif ($asset->{$fallback} !== null) {
                    $total += (float) $asset->{$fallback};
                }
            }
        }

        return $total;
    }

    #[Computed]
    public function monthToDateCashflow(): float
    {
        return (float) Transaction::whereBetween('occurred_on', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ])
            ->where('status', 'cleared')
            ->sum('amount');
    }

    #[Computed]
    public function next30DaysObligations(): float
    {
        return (float) RecurringProjection::whereBetween('due_on', [
            now()->toDateString(),
            now()->addDays(30)->toDateString(),
        ])
            ->where('amount', '<', 0)
            ->whereIn('status', ['projected', 'matched', 'overdue'])
            ->sum('amount');
    }

    /**
     * Monthly-equivalent spend across all active subscriptions. Returns
     * null if any active subscription has an unknown cadence (matches the
     * /subscriptions page convention — better dash than lie).
     */
    #[Computed]
    public function subscriptionsMonthly(): ?float
    {
        $subs = Subscription::where('state', 'active')->get(['monthly_cost_cached']);
        $sum = 0.0;
        foreach ($subs as $s) {
            if ($s->monthly_cost_cached === null) {
                return null;
            }
            // Storage is signed (outflows are negative); this tile is
            // a "subscription spend" magnitude the user reads as the
            // dollars they pay, so render the magnitude regardless.
            $sum += abs((float) $s->monthly_cost_cached);
        }

        return $sum;
    }

    #[Computed]
    public function subscriptionsCount(): int
    {
        return Subscription::where('state', 'active')->count();
    }

    /**
     * Deterministic forecast: signed net of projected bills + income over the
     * next N days. Returns [income, expense, net]. Probabilistic seasonality
     * is a future layer.
     *
     * @return array{income: float, expense: float, net: float, end_balance: float}
     */
    public function forecast(int $days): array
    {
        $rows = RecurringProjection::whereBetween('due_on', [
            now()->toDateString(),
            now()->addDays($days)->toDateString(),
        ])
            ->whereIn('status', ['projected', 'overdue'])
            ->get(['amount']);

        $income = (float) $rows->where('amount', '>', 0)->sum('amount');
        $expense = (float) $rows->where('amount', '<', 0)->sum('amount');
        $net = $income + $expense;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'end_balance' => $this->netWorth + $net,
        ];
    }

    /** @return array{income: float, expense: float, net: float, end_balance: float} */
    #[Computed]
    public function forecast30(): array
    {
        return $this->forecast(30);
    }

    /**
     * Last 6 monthly net-worth snapshots (oldest → newest) for the sparkline.
     * Empty array if rollups haven't run yet.
     *
     * @return array<int, array{taken_on: string, total: float}>
     */
    #[Computed]
    public function trend(): array
    {
        return Snapshot::query()
            ->where('kind', 'net_worth')
            ->whereNull('subject_type')
            ->orderByDesc('taken_on')
            ->limit(6)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Snapshot $s) => [
                'taken_on' => $s->taken_on?->toDateString() ?? '',
                'total' => (float) ($s->payload['total'] ?? 0),
            ])
            ->all();
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Money</h3>
        <a href="{{ route('fiscal.accounts') }}" class="text-xs text-neutral-500 hover:text-neutral-300">All →</a>
    </div>

    <div class="space-y-4">
        <div>
            <div class="text-xs text-neutral-500">Net worth</div>
            <div class="mt-1 flex items-baseline justify-between gap-4">
                <div class="text-2xl font-semibold tabular-nums text-neutral-100">
                    {{ \App\Support\Formatting::money($this->netWorth, $this->currency) }}
                </div>
                @if (count($this->trend) >= 2)
                    @php
                        $values = array_map(fn ($p) => $p['total'], $this->trend);
                        $min = min($values);
                        $max = max($values);
                        $range = ($max - $min) ?: 1;
                        $w = 90;
                        $h = 24;
                        $step = count($values) > 1 ? $w / (count($values) - 1) : 0;
                        $points = [];
                        foreach ($values as $i => $v) {
                            $x = round($i * $step, 2);
                            $y = round($h - (($v - $min) / $range) * $h, 2);
                            $points[] = "$x,$y";
                        }
                        $path = implode(' ', $points);
                        $delta = end($values) - reset($values);
                        $trendClass = $delta >= 0 ? 'text-emerald-400' : 'text-rose-400';
                    @endphp
                    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-6 w-24 {{ $trendClass }}" aria-label="{{ __('Net worth trend, last :n months', ['n' => count($values)]) }}" role="img">
                        <polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="{{ $path }}" />
                    </svg>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <div class="text-xs text-neutral-500">This month</div>
                <div class="mt-1 text-sm tabular-nums {{ $this->monthToDateCashflow >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ $this->monthToDateCashflow >= 0 ? '+' : '' }}{{ \App\Support\Formatting::money($this->monthToDateCashflow, $this->currency) }}
                </div>
            </div>
            <div>
                <div class="text-xs text-neutral-500">Next 30 days out</div>
                <div class="mt-1 text-sm tabular-nums text-neutral-300">
                    {{ \App\Support\Formatting::money($this->next30DaysObligations, $this->currency) }}
                </div>
            </div>
        </div>

        @if ($this->subscriptionsCount > 0)
            <a href="{{ route('fiscal.subscriptions') }}"
               class="block rounded-lg border border-neutral-800 bg-neutral-900/60 px-3 py-2 text-xs text-neutral-400 transition hover:border-neutral-600 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <div class="flex items-baseline justify-between gap-3">
                    <span>{{ __(':n subscriptions', ['n' => $this->subscriptionsCount]) }}</span>
                    <span class="tabular-nums text-neutral-200">
                        @if ($this->subscriptionsMonthly !== null)
                            {{ \App\Support\Formatting::money($this->subscriptionsMonthly, $this->currency) }}/mo
                        @else
                            —
                        @endif
                    </span>
                </div>
            </a>
        @endif

        @php $f = $this->forecast30; @endphp
        @if ($f['net'] !== 0.0)
            <div class="rounded-lg border border-neutral-800 bg-neutral-900/60 px-3 py-2">
                <div class="flex items-baseline justify-between gap-3 text-xs">
                    <span class="text-neutral-500">{{ __('30d projected net') }}</span>
                    <span class="tabular-nums {{ $f['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ $f['net'] >= 0 ? '+' : '' }}{{ \App\Support\Formatting::money($f['net'], $this->currency) }}
                    </span>
                </div>
                <div class="mt-0.5 flex items-baseline justify-between gap-3 text-[11px] text-neutral-500">
                    <span>{{ __('Ends near') }}</span>
                    <span class="tabular-nums text-neutral-300">
                        {{ \App\Support\Formatting::money($f['end_balance'], $this->currency) }}
                    </span>
                </div>
            </div>
        @endif
    </div>
</div>
