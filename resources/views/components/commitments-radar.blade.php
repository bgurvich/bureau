<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\Transfer;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function activeCount(): int
    {
        return Contract::whereIn('state', ['active', 'expiring'])->count();
    }

    #[Computed]
    public function expiring30(): int
    {
        return Contract::whereIn('state', ['active', 'expiring'])
            ->whereNotNull('ends_on')
            ->whereBetween('ends_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();
    }

    #[Computed]
    public function expiring90(): int
    {
        return Contract::whereIn('state', ['active', 'expiring'])
            ->whereNotNull('ends_on')
            ->whereBetween('ends_on', [now()->addDays(31)->toDateString(), now()->addDays(90)->toDateString()])
            ->count();
    }

    #[Computed]
    public function monthlyCost(): float
    {
        return (float) Contract::whereIn('state', ['active', 'expiring'])
            ->sum('monthly_cost_amount');
    }

    #[Computed]
    public function currency(): string
    {
        return \App\Support\CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    /**
     * Weighted average APR across credit/loan/mortgage accounts with a balance,
     * plus total interest paid in the last 12 months (from interest-paid category).
     *
     * @return array{apr: ?float, annual_interest: float}
     */
    #[Computed]
    public function creditRates(): array
    {
        $accounts = Account::with('loanTerms:id,account_id,interest_rate')
            ->whereIn('type', ['credit', 'loan', 'mortgage'])
            ->where('is_active', true)
            ->get();

        $ids = $accounts->pluck('id');
        $txn = Transaction::whereIn('account_id', $ids)->where('status', 'cleared')
            ->selectRaw('account_id, SUM(amount) as total')->groupBy('account_id')->pluck('total', 'account_id');
        $out = Transfer::whereIn('from_account_id', $ids)->where('status', 'cleared')
            ->selectRaw('from_account_id, SUM(from_amount) as total')->groupBy('from_account_id')->pluck('total', 'from_account_id');
        $in = Transfer::whereIn('to_account_id', $ids)->where('status', 'cleared')
            ->selectRaw('to_account_id, SUM(to_amount) as total')->groupBy('to_account_id')->pluck('total', 'to_account_id');

        $weightedNumerator = 0.0;
        $weightedDenominator = 0.0;

        foreach ($accounts as $a) {
            $balance = abs(
                (float) $a->opening_balance
                + (float) ($txn[$a->id] ?? 0)
                - (float) ($out[$a->id] ?? 0)
                + (float) ($in[$a->id] ?? 0)
            );
            if ($balance <= 0) {
                continue;
            }
            $rate = \App\Support\EffectiveRate::forAccount($a);
            $apr = $rate['apr'] ?? null;
            if ($apr === null && $a->loanTerms?->interest_rate !== null) {
                $apr = (float) $a->loanTerms->interest_rate / 100.0;
            }
            if ($apr === null) {
                continue;
            }
            $weightedNumerator += $apr * $balance;
            $weightedDenominator += $balance;
        }

        $avgApr = $weightedDenominator > 0 ? $weightedNumerator / $weightedDenominator : null;

        $interestCategoryId = Category::where('slug', 'interest-paid')->value('id');
        $annualInterest = 0.0;
        if ($interestCategoryId) {
            $annualInterest = (float) Transaction::where('category_id', $interestCategoryId)
                ->where('status', 'cleared')
                ->where('occurred_on', '>=', now()->subYear()->toDateString())
                ->sum('amount');
            $annualInterest = abs($annualInterest);
        }

        return [
            'apr' => $avgApr,
            'annual_interest' => $annualInterest,
        ];
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Commitments</h3>
        <a href="{{ route('relationships.contracts') }}" class="text-xs text-neutral-500 hover:text-neutral-300">All →</a>
    </div>

    <div class="space-y-4">
        <div class="flex items-baseline gap-6">
            <div>
                <div class="text-xs text-neutral-500">Active</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ $this->activeCount }}</div>
            </div>
            <div>
                <div class="text-xs text-neutral-500">Monthly burn</div>
                <div class="mt-1 text-sm tabular-nums text-neutral-300">
                    {{ $this->currency }} {{ number_format($this->monthlyCost, 2) }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 text-sm">
            <div class="rounded-lg border border-neutral-800 bg-neutral-900 px-3 py-2">
                <div class="text-xs text-neutral-500">Expiring ≤ 30d</div>
                <div class="tabular-nums {{ $this->expiring30 > 0 ? 'text-amber-400' : 'text-neutral-400' }}">{{ $this->expiring30 }}</div>
            </div>
            <div class="rounded-lg border border-neutral-800 bg-neutral-900 px-3 py-2">
                <div class="text-xs text-neutral-500">Expiring ≤ 90d</div>
                <div class="tabular-nums text-neutral-400">{{ $this->expiring90 }}</div>
            </div>
        </div>

        @if ($this->creditRates['apr'] !== null || $this->creditRates['annual_interest'] > 0)
            <div class="rounded-lg border border-neutral-800 bg-neutral-900 px-3 py-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <div>
                        <div class="text-xs text-neutral-500">{{ __('Avg APR on credit') }}</div>
                        <div class="tabular-nums text-rose-400">
                            @if ($this->creditRates['apr'] !== null)
                                {{ number_format($this->creditRates['apr'] * 100, 2) }}%
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-neutral-500">{{ __('Interest (12mo)') }}</div>
                        <div class="tabular-nums text-rose-400">
                            {{ $this->currency }} {{ number_format($this->creditRates['annual_interest'], 2) }}
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
