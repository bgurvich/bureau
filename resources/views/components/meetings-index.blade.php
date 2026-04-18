<?php

use App\Models\Meeting;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Meetings'])]
class extends Component
{
    #[Url(as: 'range')]
    public string $range = 'upcoming';

    #[Url(as: 'q')]
    public string $search = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->meetings, $this->grouped, $this->counts);
    }

    #[Computed]
    public function meetings(): Collection
    {
        $now = CarbonImmutable::now();

        $query = Meeting::query()
            ->with(['contacts:id,display_name'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('location', 'like', $term)
                );
            });

        if ($this->range === 'past') {
            $query->where('starts_at', '<', $now)
                ->orderByDesc('starts_at')
                ->limit(100);
        } else {
            $query->where('starts_at', '>=', $now->subHours(6))
                ->where('starts_at', '<=', $now->addDays(60))
                ->orderBy('starts_at');
        }

        return $query->get();
    }

    #[Computed]
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->meetings as $m) {
            $day = $m->starts_at?->copy()->toDateString() ?? '—';
            $groups[$day] ??= [
                'label' => $m->starts_at ? $m->starts_at->format('D · M j') : '—',
                'items' => [],
            ];
            $groups[$day]['items'][] = $m;
        }

        return $groups;
    }

    #[Computed]
    public function counts(): array
    {
        $now = CarbonImmutable::now();

        return [
            'today' => Meeting::whereBetween('starts_at', [$now->startOfDay(), $now->endOfDay()])->count(),
            'week' => Meeting::whereBetween('starts_at', [$now, $now->addDays(7)])->count(),
            'month' => Meeting::whereBetween('starts_at', [$now, $now->addDays(30)])->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Meetings') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Appointments, calls, and scheduled events.') }}</p>
        </div>
        <x-ui.new-record-button type="meeting" :label="__('New meeting')" shortcut="M" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Today') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['today'] > 0 ? 'text-neutral-100' : 'text-neutral-500' }}">{{ $this->counts['today'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Next 7d') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['week'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Next 30d') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['month'] }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="m-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="m-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Title or location…') }}">
        </div>
        <div>
            <label for="m-range" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Range') }}</label>
            <select wire:model.live="range" id="m-range"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::timeRangeFilters() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if (empty($this->grouped))
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ $range === 'past' ? __('No past meetings.') : __('Nothing on the horizon.') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->grouped as $day => $group)
                <section class="overflow-hidden rounded-lg border border-neutral-800 bg-neutral-900/40">
                    <header class="border-b border-neutral-800/60 bg-neutral-900/60 px-4 py-2 text-xs font-medium text-neutral-200">
                        {{ $group['label'] }}
                    </header>
                    <ul class="divide-y divide-neutral-800/60">
                        @foreach ($group['items'] as $m)
                            @php
                                $start = $m->starts_at;
                                $end = $m->ends_at;
                                $isPast = $start && $start->isPast();
                                $duration = $start && $end ? (int) $start->diffInMinutes($end, absolute: true) : null;
                            @endphp
                            <li class="{{ $isPast ? 'opacity-60' : '' }}">
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'meeting', 'id' => $m->id]) }})"
                                        class="flex w-full items-start gap-3 px-4 py-2 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <div class="w-16 shrink-0 text-xs tabular-nums text-neutral-400">
                                        {{ $start ? Formatting::time($start) : '—' }}
                                        @if ($duration)
                                            <div class="text-[10px] text-neutral-500">{{ $duration }}m</div>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-neutral-100">{{ $m->title }}</div>
                                        <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                            @if ($m->location)
                                                <span>{{ $m->location }}</span>
                                            @endif
                                            @if ($m->contacts->count() > 0)
                                                <span>{{ __(':n attendees', ['n' => $m->contacts->count()]) }}</span>
                                            @endif
                                            @if ($m->all_day)
                                                <span class="uppercase tracking-wider">{{ __('All day') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
