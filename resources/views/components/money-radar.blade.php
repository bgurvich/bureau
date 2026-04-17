<?php

use App\Models\Account;
use App\Models\RecurringProjection;
use App\Models\Transaction;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function netWorth(): float
    {
        return (float) Account::where('is_active', true)
            ->where('include_in_net_worth', true)
            ->sum('opening_balance');
    }

    #[Computed]
    public function monthToDateCashflow(): float
    {
        return (float) Transaction::whereBetween('occurred_on', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ])->sum('amount');
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

    #[Computed]
    public function currency(): string
    {
        return \App\Support\CurrentHousehold::get()?->default_currency ?? 'USD';
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
            <div class="mt-1 text-2xl font-semibold tabular-nums text-neutral-100">
                {{ $this->currency }} {{ number_format($this->netWorth, 2) }}
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <div class="text-xs text-neutral-500">This month</div>
                <div class="mt-1 text-sm tabular-nums {{ $this->monthToDateCashflow >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ $this->monthToDateCashflow >= 0 ? '+' : '' }}{{ number_format($this->monthToDateCashflow, 2) }}
                </div>
            </div>
            <div>
                <div class="text-xs text-neutral-500">Next 30 days out</div>
                <div class="mt-1 text-sm tabular-nums text-neutral-300">
                    {{ number_format($this->next30DaysObligations, 2) }}
                </div>
            </div>
        </div>
    </div>
</div>
