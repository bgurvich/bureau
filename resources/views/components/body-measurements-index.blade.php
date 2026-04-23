<?php

use App\Models\BodyMeasurement;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Body composition'])]
class extends Component
{
    /** Window in days for the sparkline + summary. 30 / 90 / 365. */
    #[Url(as: 'window')]
    public string $window = '90';

    /** lb | kg — display preference; stored weight is always kg. */
    #[Url(as: 'u')]
    public string $unit = 'lb';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->measurements, $this->series);
    }

    /** @return Collection<int, BodyMeasurement> */
    #[Computed]
    public function measurements(): Collection
    {
        /** @var Collection<int, BodyMeasurement> $list */
        $list = BodyMeasurement::query()
            ->with('user:id,name')
            ->where('measured_at', '>=', now()->subDays($this->windowDays())->startOfDay())
            ->orderByDesc('measured_at')
            ->get();

        return $list;
    }

    /**
     * Per-metric time series for the sparkline. Each key holds an array
     * of [timestamp => value] ordered chronologically (oldest → newest)
     * so the SVG polyline can render left-to-right.
     *
     * @return array{weight: array<int, float>, body_fat_pct: array<int, float>, muscle_pct: array<int, float>}
     */
    #[Computed]
    public function series(): array
    {
        $reverseSortedByDate = $this->measurements->sortBy('measured_at')->values();

        $weight = [];
        $fat = [];
        $muscle = [];
        foreach ($reverseSortedByDate as $m) {
            if ($m->weight_kg !== null) {
                $weight[] = $this->unit === 'kg'
                    ? (float) $m->weight_kg
                    : (float) $m->weightLb();
            }
            if ($m->body_fat_pct !== null) {
                $fat[] = (float) $m->body_fat_pct;
            }
            if ($m->muscle_pct !== null) {
                $muscle[] = (float) $m->muscle_pct;
            }
        }

        return [
            'weight' => $weight,
            'body_fat_pct' => $fat,
            'muscle_pct' => $muscle,
        ];
    }

    /**
     * Latest value + delta vs the prior reading per metric. Delta is
     * signed (negative = decrease); UI colors emerald for drops on
     * weight + fat, amber for drops on muscle — drops are only "good"
     * on the first two metrics.
     *
     * @return array<string, array{latest: float|null, delta: float|null}>
     */
    #[Computed]
    public function latestAndDelta(): array
    {
        $series = $this->series;
        $out = [];
        foreach ($series as $metric => $values) {
            if ($values === []) {
                $out[$metric] = ['latest' => null, 'delta' => null];

                continue;
            }
            $latest = (float) end($values);
            $delta = count($values) >= 2 ? $latest - (float) $values[count($values) - 2] : null;
            $out[$metric] = ['latest' => $latest, 'delta' => $delta];
        }

        return $out;
    }

    private function windowDays(): int
    {
        return match ($this->window) {
            '30' => 30,
            '365' => 365,
            default => 90,
        };
    }

    /**
     * SVG polyline path from a list of floats. Kept on the component
     * so the template can call $this->sparklinePath($values) — top-
     * level closures after the class block don't compile cleanly
     * under Volt.
     *
     * @param  array<int, float>  $values
     */
    public function sparklinePath(array $values): ?string
    {
        if (count($values) < 2) {
            return null;
        }
        $w = 200;
        $h = 40;
        $min = min($values);
        $max = max($values);
        $range = $max - $min ?: 1.0;
        $stepX = $w / (count($values) - 1);
        $points = [];
        foreach ($values as $i => $v) {
            $x = round($i * $stepX, 2);
            $y = round($h - (($v - $min) / $range) * $h, 2);
            $points[] = $x.','.$y;
        }

        return 'M'.implode(' L', $points);
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Body composition')"
        :description="__('Weight, body fat, and muscle percentage — one reading per scale session.')">
        <x-ui.new-record-button type="body_measurement" :label="__('New measurement')" />
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Window') }}</span>
        @foreach (['30' => __(':n d', ['n' => 30]), '90' => __(':n d', ['n' => 90]), '365' => __('1 y')] as $v => $l)
            @php $active = $window === $v; @endphp
            <button type="button"
                    wire:click="$set('window', '{{ $v }}')"
                    class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }}
            </button>
        @endforeach

        <span class="ml-3 text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Unit') }}</span>
        @foreach (['lb' => 'lb', 'kg' => 'kg'] as $v => $l)
            @php $active = $unit === $v; @endphp
            <button type="button"
                    wire:click="$set('unit', '{{ $v }}')"
                    class="rounded-md border px-2 py-1 text-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }}
            </button>
        @endforeach
    </div>

    @php
        $ld = $this->latestAndDelta;
        $cards = [
            'weight' => [__('Weight'), $unit, 'stroke-emerald-400', 'text-emerald-400'],
            'body_fat_pct' => [__('Body fat'), '%', 'stroke-amber-400', 'text-amber-400'],
            'muscle_pct' => [__('Muscle'), '%', 'stroke-sky-400', 'text-sky-400'],
        ];
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach ($cards as $metric => [$label, $unitLabel, $strokeClass, $color])
            @php
                $values = $this->series[$metric] ?? [];
                $latest = $ld[$metric]['latest'] ?? null;
                $delta = $ld[$metric]['delta'] ?? null;
            @endphp
            <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4">
                <div class="flex items-baseline justify-between">
                    <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">{{ $label }}</h3>
                    @if ($latest !== null)
                        <div class="flex items-baseline gap-2">
                            <span class="text-lg tabular-nums text-neutral-100">{{ number_format($latest, 1) }}</span>
                            <span class="text-[10px] text-neutral-500">{{ $unitLabel }}</span>
                        </div>
                    @else
                        <span class="text-xs text-neutral-500">—</span>
                    @endif
                </div>
                @if ($delta !== null)
                    @php
                        // Lower weight + lower fat = good → emerald; lower muscle = amber.
                        $goodDirection = $metric === 'muscle_pct' ? 'up' : 'down';
                        $isGood = $goodDirection === 'up' ? $delta > 0 : $delta < 0;
                        $signClass = abs($delta) < 0.01
                            ? 'text-neutral-500'
                            : ($isGood ? 'text-emerald-400' : 'text-amber-400');
                    @endphp
                    <div class="mt-0.5 text-[11px] tabular-nums {{ $signClass }}">
                        {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1) }} {{ $unitLabel }}
                        <span class="text-neutral-500">{{ __('vs. prior') }}</span>
                    </div>
                @endif
                @php
                    $path = $this->sparklinePath($values);
                @endphp
                @if ($path)
                    <div class="mt-2 {{ $color }}">
                        <svg viewBox="0 0 200 40" class="h-10 w-full overflow-visible" aria-hidden="true">
                            <path d="{{ $path }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="{{ $strokeClass }}"/>
                        </svg>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($this->measurements->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No measurements in this window. Step on the scale.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->measurements as $m)
                @php
                    $wDisplay = null;
                    if ($m->weight_kg !== null) {
                        $wDisplay = $unit === 'kg'
                            ? (float) $m->weight_kg
                            : (float) $m->weightLb();
                    }
                @endphp
                <x-ui.inspector-row type="body_measurement" :id="$m->id" :label="$m->measured_at->format('M j, H:i')" class="flex items-baseline gap-4 px-4 py-3 text-sm">
                    <span class="w-32 shrink-0 font-mono text-xs text-neutral-500">{{ $m->measured_at->format('M j, H:i') }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-4 tabular-nums text-neutral-100">
                            @if ($wDisplay !== null)
                                <span>{{ number_format($wDisplay, 1) }}<span class="ml-0.5 text-[10px] text-neutral-500">{{ $unit }}</span></span>
                            @endif
                            @if ($m->body_fat_pct !== null)
                                <span class="text-amber-300">{{ number_format((float) $m->body_fat_pct, 1) }}<span class="ml-0.5 text-[10px] text-neutral-500">% {{ __('fat') }}</span></span>
                            @endif
                            @if ($m->muscle_pct !== null)
                                <span class="text-sky-300">{{ number_format((float) $m->muscle_pct, 1) }}<span class="ml-0.5 text-[10px] text-neutral-500">% {{ __('muscle') }}</span></span>
                            @endif
                        </div>
                        @if ($m->notes)
                            <div class="mt-0.5 truncate text-[11px] text-neutral-500">{{ $m->notes }}</div>
                        @endif
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>
