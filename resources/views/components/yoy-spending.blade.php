<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Year of the "current" side of the comparison. Defaults to the
     * calendar year; query param `?year=2025` lets the user drill into
     * prior comparisons (e.g. 2024 vs 2023).
     */
    public int $year;

    public function mount(): void
    {
        $this->year = (int) (request()->query('year') ?: CarbonImmutable::now()->year);
    }

    /**
     * Returns a month-by-month table: 12 rows (Jan–Dec), with columns for
     * current-year and prior-year outflow totals. "Outflow" = abs value of
     * negative amounts; positive amounts (refunds, income) are intentionally
     * excluded because mixing signs muddies the category story.
     *
     * @return array<int, array{month: string, current: float, prior: float, delta: float}>
     */
    #[Computed]
    public function monthly(): array
    {
        $start = CarbonImmutable::create($this->year - 1, 1, 1);
        $end = CarbonImmutable::create($this->year, 12, 31);

        $rows = Transaction::query()
            ->where('amount', '<', 0)
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->select([
                DB::raw('YEAR(occurred_on) as y'),
                DB::raw('MONTH(occurred_on) as m'),
                DB::raw('SUM(ABS(amount)) as total'),
            ])
            ->groupBy('y', 'm')
            ->orderBy('y')
            ->orderBy('m')
            ->get();

        $bucket = [];
        foreach ($rows as $r) {
            $bucket[(int) $r->y][(int) $r->m] = (float) $r->total;
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $current = $bucket[$this->year][$m] ?? 0.0;
            $prior = $bucket[$this->year - 1][$m] ?? 0.0;
            $months[] = [
                'month' => CarbonImmutable::create($this->year, $m, 1)->format('M'),
                'current' => $current,
                'prior' => $prior,
                'delta' => $current - $prior,
            ];
        }

        return $months;
    }

    #[Computed]
    public function totals(): array
    {
        $months = $this->monthly;
        $c = array_sum(array_column($months, 'current'));
        $p = array_sum(array_column($months, 'prior'));

        return ['current' => $c, 'prior' => $p, 'delta' => $c - $p];
    }

    #[Computed]
    public function maxMonthly(): float
    {
        $months = $this->monthly;
        $all = array_merge(array_column($months, 'current'), array_column($months, 'prior'));

        return (float) (max($all) ?: 1);
    }

    /**
     * Per-category outflow totals for current vs prior year. Sorted by
     * current-year outflow so the biggest categories are at the top.
     *
     * @return array<int, array{category: string, current: float, prior: float, delta: float}>
     */
    #[Computed]
    public function byCategory(): array
    {
        $start = CarbonImmutable::create($this->year - 1, 1, 1);
        $end = CarbonImmutable::create($this->year, 12, 31);

        $rows = Transaction::query()
            ->where('amount', '<', 0)
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->select([
                DB::raw('YEAR(occurred_on) as y'),
                'category_id',
                DB::raw('SUM(ABS(amount)) as total'),
            ])
            ->groupBy('y', 'category_id')
            ->get();

        $byCatYear = [];
        foreach ($rows as $r) {
            $byCatYear[(int) ($r->category_id ?? 0)][(int) $r->y] = (float) $r->total;
        }

        $categories = Category::whereIn('id', array_filter(array_keys($byCatYear)))
            ->get(['id', 'name'])
            ->keyBy('id');

        $result = [];
        foreach ($byCatYear as $catId => $years) {
            $label = $catId === 0 ? __('(uncategorized)') : ($categories[$catId]->name ?? __('—'));
            $current = $years[$this->year] ?? 0.0;
            $prior = $years[$this->year - 1] ?? 0.0;
            if ($current == 0.0 && $prior == 0.0) {
                continue;
            }
            $result[] = [
                'category' => $label,
                'category_id' => $catId,
                'current' => $current,
                'prior' => $prior,
                'delta' => $current - $prior,
            ];
        }

        usort($result, fn ($a, $b) => $b['current'] <=> $a['current']);

        return $result;
    }

    #[Computed]
    public function maxCategory(): float
    {
        $rows = $this->byCategory;
        if ($rows === []) {
            return 1.0;
        }
        $all = array_merge(array_column($rows, 'current'), array_column($rows, 'prior'));

        return (float) (max($all) ?: 1);
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Year over year — spending')"
        :description="__(':current vs :prior. Outflows only.', ['current' => $year, 'prior' => $year - 1])">
        <a href="?year={{ $year - 1 }}"
           class="rounded-md border border-neutral-800 px-2 py-1 text-xs text-neutral-400 hover:border-neutral-600 hover:text-neutral-100">← {{ $year - 1 }}</a>
        @if ($year < (int) now()->year)
            <a href="?year={{ $year + 1 }}"
               class="rounded-md border border-neutral-800 px-2 py-1 text-xs text-neutral-400 hover:border-neutral-600 hover:text-neutral-100">{{ $year + 1 }} →</a>
        @endif
    </x-ui.page-header>

    <div class="grid grid-cols-3 gap-3 rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-xs">
        <div>
            <div class="text-neutral-500">{{ $year }}</div>
            <div class="mt-1 font-mono tabular-nums text-neutral-100">{{ Formatting::money($this->totals['current'], $this->currency) }}</div>
        </div>
        <div>
            <div class="text-neutral-500">{{ $year - 1 }}</div>
            <div class="mt-1 font-mono tabular-nums text-neutral-100">{{ Formatting::money($this->totals['prior'], $this->currency) }}</div>
        </div>
        <div>
            <div class="text-neutral-500">{{ __('Delta') }}</div>
            <div class="mt-1 font-mono tabular-nums {{ $this->totals['delta'] > 0 ? 'text-rose-400' : 'text-emerald-400' }}">
                {{ $this->totals['delta'] > 0 ? '+' : '' }}{{ Formatting::money($this->totals['delta'], $this->currency) }}
            </div>
        </div>
    </div>

    <h3 class="mt-2 text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('By month') }}</h3>
    <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40">
        <table class="w-full min-w-[40rem] text-sm">
            <thead class="border-b border-neutral-800 bg-neutral-900/60 text-[10px] uppercase tracking-wider text-neutral-500">
                <tr>
                    <th scope="col" class="px-3 py-2 text-left font-medium">{{ __('Month') }}</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">{{ $year }}</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">{{ $year - 1 }}</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">{{ __('Delta') }}</th>
                    <th scope="col" class="px-3 py-2 text-left font-medium">{{ __('Comparison') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800">
                @foreach ($this->monthly as $m)
                    <tr>
                        <td class="px-3 py-2 font-mono text-[11px] text-neutral-500">{{ $m['month'] }}</td>
                        <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-200">{{ Formatting::money($m['current'], $this->currency) }}</td>
                        <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-400">{{ Formatting::money($m['prior'], $this->currency) }}</td>
                        <td class="px-3 py-2 text-right font-mono tabular-nums {{ $m['delta'] > 0 ? 'text-rose-400' : 'text-emerald-400' }}">
                            {{ $m['delta'] > 0 ? '+' : '' }}{{ Formatting::money($m['delta'], $this->currency) }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex h-3 w-full gap-0.5">
                                <div class="h-full bg-neutral-100"
                                     style="width: {{ max(1, $this->maxMonthly > 0 ? $m['current'] / $this->maxMonthly * 100 : 0) }}%"
                                     aria-label="{{ __(':y: :amt', ['y' => $year, 'amt' => Formatting::money($m['current'], $this->currency)]) }}"></div>
                            </div>
                            <div class="mt-0.5 flex h-2 w-full gap-0.5">
                                <div class="h-full bg-neutral-600"
                                     style="width: {{ max(1, $this->maxMonthly > 0 ? $m['prior'] / $this->maxMonthly * 100 : 0) }}%"
                                     aria-label="{{ __(':y: :amt', ['y' => $year - 1, 'amt' => Formatting::money($m['prior'], $this->currency)]) }}"></div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3 class="mt-2 text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('By category') }}</h3>
    @if ($this->byCategory === [])
        <x-ui.empty-state>
            {{ __('No categorized outflows in either year.') }}
        </x-ui.empty-state>
    @else
        <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="border-b border-neutral-800 bg-neutral-900/60 text-[10px] uppercase tracking-wider text-neutral-500">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left font-medium">{{ __('Category') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ $year }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ $year - 1 }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ __('Delta') }}</th>
                        <th scope="col" class="px-3 py-2 text-left font-medium">{{ __('Comparison') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800">
                    @foreach ($this->byCategory as $row)
                        @php
                            $filterUrl = route('fiscal.transactions', array_filter([
                                'category' => $row['category_id'] ?: null,
                                'from' => $year.'-01-01',
                                'to' => $year.'-12-31',
                            ]));
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-neutral-100">
                                <a href="{{ $filterUrl }}" class="underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ $row['category'] }}</a>
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-200">{{ Formatting::money($row['current'], $this->currency) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-400">{{ Formatting::money($row['prior'], $this->currency) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums {{ $row['delta'] > 0 ? 'text-rose-400' : 'text-emerald-400' }}">
                                {{ $row['delta'] > 0 ? '+' : '' }}{{ Formatting::money($row['delta'], $this->currency) }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex h-3 w-full gap-0.5">
                                    <div class="h-full bg-neutral-100"
                                         style="width: {{ max(1, $this->maxCategory > 0 ? $row['current'] / $this->maxCategory * 100 : 0) }}%"
                                         aria-label="{{ __(':y: :amt', ['y' => $year, 'amt' => Formatting::money($row['current'], $this->currency)]) }}"></div>
                                </div>
                                <div class="mt-0.5 flex h-2 w-full gap-0.5">
                                    <div class="h-full bg-neutral-600"
                                         style="width: {{ max(1, $this->maxCategory > 0 ? $row['prior'] / $this->maxCategory * 100 : 0) }}%"
                                         aria-label="{{ __(':y: :amt', ['y' => $year - 1, 'amt' => Formatting::money($row['prior'], $this->currency)]) }}"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
