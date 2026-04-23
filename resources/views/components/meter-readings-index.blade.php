<?php

use App\Models\MeterReading;
use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Meter readings'])]
class extends Component
{
    #[Url(as: 'property')]
    public ?int $propertyFilter = null;

    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->readings);
    }

    /** @return Collection<int, MeterReading> */
    #[Computed]
    public function readings(): Collection
    {
        /** @var Collection<int, MeterReading> $list */
        $list = MeterReading::query()
            ->with('property:id,name')
            ->when($this->propertyFilter !== null, fn ($q) => $q->where('property_id', $this->propertyFilter))
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->orderByDesc('read_on')
            ->orderByDesc('id')
            ->get();

        return $list;
    }

    /**
     * Delta map: reading id → [delta value, prior reading's read_on].
     * Computed in PHP rather than SQL so cross-(property,kind) ordering
     * stays obvious and test-able. Null entry = no prior reading yet.
     *
     * @return array<int, array{delta: float, prior_read_on: string, days: int}|null>
     */
    #[Computed]
    public function deltas(): array
    {
        $out = [];
        $buckets = $this->readings->groupBy(fn (MeterReading $r) => $r->property_id.'|'.$r->kind);
        foreach ($buckets as $series) {
            // Within each (property, kind) series, walk oldest → newest
            // so the delta against the immediately previous reading is
            // straightforward. $series is desc-sorted from the outer
            // query; reverse it for the ascending walk.
            $series = $series->reverse()->values();
            $prior = null;
            foreach ($series as $r) {
                if ($prior === null) {
                    $out[$r->id] = null;
                } else {
                    $out[$r->id] = [
                        'delta' => (float) $r->value - (float) $prior->value,
                        'prior_read_on' => $prior->read_on->toDateString(),
                        'days' => (int) $prior->read_on->diffInDays($r->read_on, absolute: true),
                    ];
                }
                $prior = $r;
            }
        }

        return $out;
    }

    /** @return Collection<int, Property> */
    #[Computed]
    public function properties(): Collection
    {
        /** @var Collection<int, Property> $list */
        $list = Property::orderBy('name')->get(['id', 'name']);

        return $list;
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Meter readings')"
        :description="__('Utility usage log — water, electric, gas. Log a fresh reading each month to spot leaks and spikes.')">
        <x-ui.new-record-button type="meter_reading" :label="__('New reading')" />
    </x-ui.page-header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="mr-property" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Property') }}</label>
            <select wire:model.live="propertyFilter" id="mr-property"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->properties as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="mr-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="mr-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (\App\Support\Enums::meterReadingKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->readings->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No readings logged yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->readings as $r)
                @php
                    $delta = $this->deltas[$r->id] ?? null;
                    $kindLabel = \App\Support\Enums::meterReadingKinds()[$r->kind] ?? $r->kind;
                @endphp
                <x-ui.inspector-row type="meter_reading" :id="$r->id" :label="$kindLabel" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300">{{ $kindLabel }}</span>
                            <span class="font-medium text-neutral-100">{{ $r->property?->name ?? __('(no property)') }}</span>
                            <span class="text-xs text-neutral-500">{{ \App\Support\Formatting::date($r->read_on) }}</span>
                        </div>
                        @if ($delta !== null)
                            @php
                                $amount = $delta['delta'];
                                $days = $delta['days'];
                                $sign = $amount >= 0 ? '+' : '';
                                $class = $amount >= 0 ? 'text-amber-400' : 'text-emerald-400';
                            @endphp
                            <div class="mt-0.5 text-[11px] tabular-nums text-neutral-500">
                                <span class="{{ $class }}">{{ $sign }}{{ rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.') }} {{ $r->unit }}</span>
                                <span class="ml-1">{{ __('over :d days since :on', ['d' => $days, 'on' => $delta['prior_read_on']]) }}</span>
                            </div>
                        @else
                            <div class="mt-0.5 text-[11px] text-neutral-600">{{ __('First reading in this series.') }}</div>
                        @endif
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="text-sm tabular-nums text-neutral-100">
                            {{ rtrim(rtrim(number_format((float) $r->value, 4, '.', ''), '0'), '.') }}
                            <span class="text-[10px] text-neutral-500">{{ $r->unit }}</span>
                        </div>
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>
