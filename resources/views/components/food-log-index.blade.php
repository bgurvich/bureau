<?php

use App\Models\FoodEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Food log'])]
class extends Component
{
    /** ISO date to focus on (YYYY-MM-DD). Defaults to today. */
    #[Url(as: 'date')]
    public string $dateFilter = '';

    /** Direction arrows bump this N days. Cleaner than date-picker only. */
    public function shiftDay(int $delta): void
    {
        $target = $this->dateFilter !== '' ? $this->dateFilter : now()->toDateString();
        $this->dateFilter = Carbon::parse($target)->addDays($delta)->toDateString();
    }

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->entries, $this->totals, $this->weekTotals);
    }

    public function effectiveDate(): string
    {
        return $this->dateFilter !== '' ? $this->dateFilter : now()->toDateString();
    }

    /** @return Collection<int, FoodEntry> */
    #[Computed]
    public function entries(): Collection
    {
        /** @var Collection<int, FoodEntry> $list */
        $list = FoodEntry::query()
            ->with('user:id,name')
            ->whereDate('eaten_at', $this->effectiveDate())
            ->orderBy('eaten_at')
            ->get();

        return $list;
    }

    /**
     * Day-level totals (calories + macros), accumulated across the
     * filtered day's entries. Null fields in a row contribute zero —
     * users often log a meal name without plugging in macros, and the
     * aggregate shouldn't refuse to render over that.
     *
     * @return array{calories: int, protein_g: float, carbs_g: float, fat_g: float}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'calories' => (int) $this->entries->sum(fn ($e) => (int) ($e->calories ?? 0)),
            'protein_g' => (float) $this->entries->sum(fn ($e) => (float) ($e->protein_g ?? 0)),
            'carbs_g' => (float) $this->entries->sum(fn ($e) => (float) ($e->carbs_g ?? 0)),
            'fat_g' => (float) $this->entries->sum(fn ($e) => (float) ($e->fat_g ?? 0)),
        ];
    }

    /**
     * Trailing-7-day calorie totals keyed by ISO date — powers the
     * mini bar chart at the top of the page. Renders even when the
     * user is drilled into an older day so the trend stays in view.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function weekTotals(): array
    {
        $end = Carbon::parse($this->effectiveDate());
        $start = $end->copy()->subDays(6);
        $rows = FoodEntry::query()
            ->selectRaw('DATE(eaten_at) as d, COALESCE(SUM(calories), 0) as cals')
            ->whereBetween('eaten_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()])
            ->groupByRaw('DATE(eaten_at)')
            ->pluck('cals', 'd')
            ->map(fn ($v) => (int) $v)
            ->all();

        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $out[$d] = $rows[$d] ?? 0;
        }

        return $out;
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Food log')"
        :description="__('What you ate today. Nutrition fields are optional — a rough log is still useful.')">
        <x-ui.new-record-button type="food_entry" :label="__('New entry')" shortcut="F" />
    </x-ui.page-header>

    @php
        $today = $this->effectiveDate();
    @endphp
    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4">
        <button type="button" wire:click="shiftDay(-1)"
                class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                aria-label="{{ __('Previous day') }}">←</button>
        <label for="fd-date" class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Date') }}</label>
        <input wire:model.live="dateFilter" id="fd-date" type="date"
               class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <button type="button" wire:click="shiftDay(1)"
                class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                aria-label="{{ __('Next day') }}">→</button>
        <button type="button" wire:click="$set('dateFilter', '{{ now()->toDateString() }}')"
                class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-[11px] text-neutral-400 hover:border-neutral-500 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Today') }}
        </button>
    </div>

    <div class="grid gap-3 md:grid-cols-4">
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Calories') }}</div>
            <div class="mt-0.5 text-lg tabular-nums text-neutral-100">{{ number_format($this->totals['calories']) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Protein') }}</div>
            <div class="mt-0.5 text-lg tabular-nums text-neutral-100">{{ rtrim(rtrim(number_format($this->totals['protein_g'], 1, '.', ''), '0'), '.') ?: '0' }}<span class="text-xs text-neutral-500"> g</span></div>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Carbs') }}</div>
            <div class="mt-0.5 text-lg tabular-nums text-neutral-100">{{ rtrim(rtrim(number_format($this->totals['carbs_g'], 1, '.', ''), '0'), '.') ?: '0' }}<span class="text-xs text-neutral-500"> g</span></div>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Fat') }}</div>
            <div class="mt-0.5 text-lg tabular-nums text-neutral-100">{{ rtrim(rtrim(number_format($this->totals['fat_g'], 1, '.', ''), '0'), '.') ?: '0' }}<span class="text-xs text-neutral-500"> g</span></div>
        </div>
    </div>

    @php
        $max = max(1, max($this->weekTotals));
    @endphp
    <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4">
        <h3 class="mb-3 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Last 7 days') }}</h3>
        <div class="flex items-end gap-2">
            @foreach ($this->weekTotals as $d => $cals)
                @php
                    $height = max(4, (int) round($cals / $max * 72));
                    $isFocus = $d === $today;
                @endphp
                <button type="button" wire:click="$set('dateFilter', '{{ $d }}')"
                        class="flex flex-1 flex-col items-center gap-1 rounded px-1 py-1 text-center text-[10px] tabular-nums text-neutral-500 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $isFocus ? 'text-neutral-200' : '' }}">
                    <span>{{ number_format($cals) }}</span>
                    <span class="w-full rounded-sm {{ $isFocus ? 'bg-neutral-300' : 'bg-neutral-600' }}" style="height: {{ $height }}px;" aria-hidden="true"></span>
                    <span>{{ \Illuminate\Support\Carbon::parse($d)->format('D') }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($this->entries->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('Nothing logged for this day yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->entries as $e)
                @php
                    $kindLabel = \App\Support\Enums::foodEntryKinds()[$e->kind] ?? $e->kind;
                @endphp
                <x-ui.inspector-row type="food_entry" :id="$e->id" :label="$e->label" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="w-16 shrink-0 font-mono text-sm text-neutral-500">{{ $e->eaten_at->format('H:i') }}</span>
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300">{{ $kindLabel }}</span>
                            <span class="truncate font-medium text-neutral-100">{{ $e->label }}</span>
                        </div>
                        @if ($e->protein_g !== null || $e->carbs_g !== null || $e->fat_g !== null)
                            <div class="mt-0.5 ml-16 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($e->protein_g !== null)<span>{{ __(':n g protein', ['n' => rtrim(rtrim(number_format((float) $e->protein_g, 1, '.', ''), '0'), '.')]) }}</span>@endif
                                @if ($e->carbs_g !== null)<span>{{ __(':n g carbs', ['n' => rtrim(rtrim(number_format((float) $e->carbs_g, 1, '.', ''), '0'), '.')]) }}</span>@endif
                                @if ($e->fat_g !== null)<span>{{ __(':n g fat', ['n' => rtrim(rtrim(number_format((float) $e->fat_g, 1, '.', ''), '0'), '.')]) }}</span>@endif
                            </div>
                        @endif
                    </div>
                    <div class="shrink-0 text-right tabular-nums">
                        @if ($e->calories !== null)
                            <div class="text-sm text-neutral-100">{{ number_format($e->calories) }}<span class="text-[10px] text-neutral-500"> cal</span></div>
                        @endif
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>
