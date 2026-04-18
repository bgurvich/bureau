<?php

use App\Models\Prescription;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Prescriptions'])]
class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public bool $activeOnly = true;

    #[Computed]
    public function prescriptions(): Collection
    {
        $today = now()->toDateString();

        return Prescription::query()
            ->with(['prescriber:id,name,specialty', 'subject'])
            ->when($this->activeOnly, fn ($q) => $q
                ->where(fn ($inner) => $inner
                    ->whereNull('active_to')
                    ->orWhereDate('active_to', '>=', $today)
                )
            )
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('name', 'like', $term);
            })
            ->orderByRaw('next_refill_on IS NULL, next_refill_on')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(7)->toDateString();

        return [
            'active' => Prescription::query()
                ->where(fn ($q) => $q->whereNull('active_to')->orWhereDate('active_to', '>=', $today))
                ->count(),
            'refill_soon' => Prescription::query()
                ->whereNotNull('next_refill_on')
                ->whereDate('next_refill_on', '<=', $soon)
                ->count(),
            'no_refills' => Prescription::query()
                ->where('refills_left', 0)
                ->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Prescriptions') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Active meds with refill schedule.') }}</p>
        </div>
        <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Active') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['active'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Refill ≤ 7d') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['refill_soon'] > 0 ? 'text-amber-400' : 'text-neutral-500' }}">{{ $this->counts['refill_soon'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Out of refills') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['no_refills'] > 0 ? 'text-rose-400' : 'text-neutral-500' }}">{{ $this->counts['no_refills'] }}</dd>
            </div>
        </dl>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="rx-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="rx-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Medication…') }}">
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="activeOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Active only') }}
        </label>
    </form>

    @if ($this->prescriptions->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No prescriptions yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->prescriptions as $rx)
                @php
                    $refill = $rx->next_refill_on ? CarbonImmutable::parse($rx->next_refill_on) : null;
                    $daysToRefill = $refill ? (int) now()->startOfDay()->diffInDays($refill, absolute: false) : null;
                    $refillClass = match (true) {
                        $daysToRefill === null => 'text-neutral-500',
                        $daysToRefill < 0 => 'text-rose-400',
                        $daysToRefill <= 7 => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                @endphp
                <li class="flex items-start justify-between gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate text-neutral-100">{{ $rx->name }}</span>
                            @if ($rx->dosage)
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] text-neutral-400">{{ $rx->dosage }}</span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($rx->schedule)
                                <span>{{ $rx->schedule }}</span>
                            @endif
                            @if ($rx->prescriber)
                                <span>{{ $rx->prescriber->name }}</span>
                            @endif
                            @if ($rx->refills_left !== null)
                                <span class="{{ $rx->refills_left === 0 ? 'text-rose-400' : '' }}">{{ __(':n refills left', ['n' => $rx->refills_left]) }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($refill)
                            <div class="text-xs tabular-nums {{ $refillClass }}">
                                {{ Formatting::date($refill) }}
                            </div>
                            <div class="text-[10px] uppercase tracking-wider {{ $refillClass }}">
                                @if ($daysToRefill < 0)
                                    {{ __('overdue') }}
                                @else
                                    {{ $daysToRefill }}d {{ __('to refill') }}
                                @endif
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
