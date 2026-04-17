<?php

use App\Models\Contract;
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
    </div>
</div>
