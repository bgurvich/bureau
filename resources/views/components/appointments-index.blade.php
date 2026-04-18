<?php

use App\Models\Appointment;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Appointments'])]
class extends Component
{
    #[Url(as: 'range')]
    public string $range = 'upcoming';

    #[Url(as: 'q')]
    public string $search = '';

    #[Computed]
    public function appointments(): Collection
    {
        $now = CarbonImmutable::now();

        $query = Appointment::query()
            ->with(['provider:id,name,specialty'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('purpose', 'like', $term)
                    ->orWhere('location', 'like', $term)
                );
            });

        if ($this->range === 'past') {
            $query->where('starts_at', '<', $now)
                ->orderByDesc('starts_at')
                ->limit(100);
        } else {
            $query->where('starts_at', '>=', $now->subHours(6))
                ->where('starts_at', '<=', $now->addMonths(6))
                ->orderBy('starts_at');
        }

        return $query->get();
    }

    #[Computed]
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->appointments as $a) {
            $day = $a->starts_at?->copy()->toDateString() ?? '—';
            $groups[$day] ??= [
                'label' => $a->starts_at ? $a->starts_at->format('D · M j · Y') : '—',
                'items' => [],
            ];
            $groups[$day]['items'][] = $a;
        }

        return $groups;
    }

    #[Computed]
    public function counts(): array
    {
        $now = CarbonImmutable::now();

        return [
            'next_30d' => Appointment::whereBetween('starts_at', [$now, $now->addDays(30)])->count(),
            'today' => Appointment::whereBetween('starts_at', [$now->startOfDay(), $now->endOfDay()])->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Appointments') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Doctor visits, checkups, follow-ups.') }}</p>
        </div>
        <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Today') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['today'] > 0 ? 'text-amber-400' : 'text-neutral-500' }}">{{ $this->counts['today'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Next 30d') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['next_30d'] }}</dd>
            </div>
        </dl>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="ap-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="ap-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Purpose or location…') }}">
        </div>
        <div>
            <label for="ap-range" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Range') }}</label>
            <select wire:model.live="range" id="ap-range"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::timeRangeFilters() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if (empty($this->grouped))
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ $range === 'past' ? __('No past appointments.') : __('Nothing scheduled.') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->grouped as $day => $group)
                <section class="overflow-hidden rounded-lg border border-neutral-800 bg-neutral-900/40">
                    <header class="border-b border-neutral-800/60 bg-neutral-900/60 px-4 py-2 text-xs font-medium text-neutral-200">
                        {{ $group['label'] }}
                    </header>
                    <ul class="divide-y divide-neutral-800/60">
                        @foreach ($group['items'] as $a)
                            @php
                                $isPast = $a->starts_at?->isPast();
                                $duration = ($a->starts_at && $a->ends_at)
                                    ? (int) $a->starts_at->diffInMinutes($a->ends_at, absolute: true)
                                    : null;
                            @endphp
                            <li class="flex items-start gap-3 px-4 py-2 text-sm {{ $isPast ? 'opacity-60' : '' }}">
                                <div class="w-16 shrink-0 text-xs tabular-nums text-neutral-400">
                                    {{ $a->starts_at ? Formatting::time($a->starts_at) : '—' }}
                                    @if ($duration)
                                        <div class="text-[10px] text-neutral-500">{{ $duration }}m</div>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline gap-2">
                                        <span class="truncate text-neutral-100">{{ $a->provider?->name ?? $a->purpose ?? __('Appointment') }}</span>
                                        @if ($a->state && $a->state !== 'scheduled')
                                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $a->state }}</span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                        @if ($a->purpose && $a->provider)
                                            <span>{{ $a->purpose }}</span>
                                        @endif
                                        @if ($a->location)
                                            <span>{{ $a->location }}</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
