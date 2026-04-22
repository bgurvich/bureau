<?php

use App\Models\Account;
use App\Models\Appointment;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Meeting;
use App\Models\Prescription;
use App\Models\RecurringProjection;
use App\Models\Task;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Calendar'])]
class extends Component
{
    /** YYYY-MM-DD anchor. Empty = today. */
    #[Url(as: 'd')]
    public string $cursor = '';

    /** Active view: month (default), week, or day. */
    #[Url(as: 'v')]
    public string $view = 'month';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->events);
    }

    public function setView(string $view): void
    {
        if (in_array($view, ['month', 'week', 'day'], true)) {
            $this->view = $view;
            unset($this->events);
        }
    }

    public function go(int $delta): void
    {
        $anchor = $this->anchor();
        $this->cursor = match ($this->view) {
            'day' => $anchor->addDays($delta)->toDateString(),
            'week' => $anchor->addWeeks($delta)->toDateString(),
            default => $anchor->addMonthsNoOverflow($delta)->toDateString(),
        };
    }

    public function today(): void
    {
        $this->cursor = '';
    }

    public function anchor(): CarbonImmutable
    {
        try {
            return $this->cursor
                ? CarbonImmutable::createFromFormat('!Y-m-d', $this->cursor)
                : CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    /** First day visible in the current view's grid. */
    public function rangeStart(): CarbonImmutable
    {
        $weekStart = (int) (auth()->user()->week_starts_on ?? 0);
        $anchor = $this->anchor();

        return match ($this->view) {
            'day' => $anchor,
            'week' => $this->snapToWeekStart($anchor, $weekStart),
            default => $this->snapToWeekStart($anchor->startOfMonth(), $weekStart),
        };
    }

    /** Last day visible in the current view's grid (inclusive). */
    public function rangeEnd(): CarbonImmutable
    {
        return match ($this->view) {
            'day' => $this->rangeStart(),
            'week' => $this->rangeStart()->addDays(6),
            default => $this->rangeStart()->addDays(41), // 6x7 month grid
        };
    }

    private function snapToWeekStart(CarbonImmutable $d, int $weekStart): CarbonImmutable
    {
        while ($d->dayOfWeek !== $weekStart) {
            $d = $d->subDay();
        }

        return $d;
    }

    /**
     * All events in the visible grid range, grouped by YYYY-MM-DD.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    #[Computed]
    public function events(): Collection
    {
        $start = $this->rangeStart();
        $end = $this->rangeEnd()->addDay(); // inclusive end → exclusive sentinel for whereBetween semantics
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $all = collect();

        $all = $all->merge(
            Task::whereIn('state', ['open', 'waiting'])
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [$start->startOfDay(), $end->endOfDay()])
                ->get(['id', 'title', 'due_at'])
                ->map(fn ($t) => [
                    'date' => $t->due_at?->toDateString(),
                    'time' => $t->due_at?->format('H:i'),
                    'title' => $t->title,
                    'type' => 'task',
                    'id' => $t->id,
                    'class' => 'bg-amber-900/40 text-amber-200 border-amber-800/40',
                ])
        );

        $all = $all->merge(
            Meeting::where('status', '!=', 'cancelled')
                ->whereNotNull('starts_at')
                ->whereBetween('starts_at', [$start->startOfDay(), $end->endOfDay()])
                ->get(['id', 'title', 'starts_at'])
                ->map(fn ($m) => [
                    'date' => $m->starts_at?->toDateString(),
                    'time' => $m->starts_at?->format('H:i'),
                    'title' => $m->title,
                    'type' => 'meeting',
                    'id' => $m->id,
                    'class' => 'bg-sky-900/40 text-sky-200 border-sky-800/40',
                ])
        );

        $all = $all->merge(
            Appointment::whereIn('state', ['scheduled', 'completed'])
                ->whereNotNull('starts_at')
                ->whereBetween('starts_at', [$start->startOfDay(), $end->endOfDay()])
                ->get(['id', 'purpose', 'starts_at'])
                ->map(fn ($a) => [
                    'date' => $a->starts_at?->toDateString(),
                    'time' => $a->starts_at?->format('H:i'),
                    'title' => $a->purpose ?: __('Appointment'),
                    'type' => 'appointment',
                    'id' => $a->id,
                    'class' => 'bg-violet-900/40 text-violet-200 border-violet-800/40',
                ])
        );

        $all = $all->merge(
            RecurringProjection::with('rule:id,title')
                ->whereIn('status', ['projected', 'overdue'])
                ->whereBetween('due_on', [$startStr, $endStr])
                ->whereNotNull('rule_id')
                ->get(['id', 'rule_id', 'amount', 'due_on'])
                ->map(fn ($p) => [
                    'date' => $p->due_on?->toDateString(),
                    'time' => null,
                    'title' => $p->rule?->title ?? __('Scheduled item'),
                    // Inspector 'bill' type loads a RecurringRule — dispatch the rule id,
                    // not the projection id, or the Inspector 404s.
                    'type' => 'bill',
                    'id' => $p->rule_id,
                    'class' => ((float) $p->amount < 0)
                        ? 'bg-rose-900/40 text-rose-200 border-rose-800/40'
                        : 'bg-emerald-900/40 text-emerald-200 border-emerald-800/40',
                ])
        );

        $all = $all->merge(
            Document::whereNotNull('expires_on')
                ->whereBetween('expires_on', [$startStr, $endStr])
                ->get(['id', 'label', 'kind', 'expires_on'])
                ->map(fn ($d) => [
                    'date' => $d->expires_on?->toDateString(),
                    'time' => null,
                    'title' => ($d->label ?: ucfirst((string) $d->kind)).' '.__('expires'),
                    'type' => 'document',
                    'id' => $d->id,
                    'class' => 'bg-rose-900/40 text-rose-200 border-rose-800/40',
                ])
        );

        // Contracts: end + trial end are the actionable dates.
        $all = $all->merge(
            Contract::whereNotIn('state', ['ended', 'cancelled'])
                ->where(fn ($q) => $q
                    ->whereBetween('ends_on', [$startStr, $endStr])
                    ->orWhereBetween('trial_ends_on', [$startStr, $endStr])
                )
                ->get(['id', 'title', 'ends_on', 'trial_ends_on'])
                ->flatMap(function ($c) use ($startStr, $endStr) {
                    $events = [];
                    if ($c->trial_ends_on && $c->trial_ends_on->between($startStr, $endStr)) {
                        $events[] = [
                            'date' => $c->trial_ends_on->toDateString(),
                            'time' => null,
                            'title' => $c->title.' — '.__('trial ends'),
                            'type' => 'contract',
                            'id' => $c->id,
                            'class' => 'bg-amber-900/40 text-amber-200 border-amber-800/40',
                        ];
                    }
                    if ($c->ends_on && $c->ends_on->between($startStr, $endStr)) {
                        $events[] = [
                            'date' => $c->ends_on->toDateString(),
                            'time' => null,
                            'title' => $c->title.' — '.__('ends'),
                            'type' => 'contract',
                            'id' => $c->id,
                            'class' => 'bg-rose-900/40 text-rose-200 border-rose-800/40',
                        ];
                    }

                    return $events;
                })
        );

        $all = $all->merge(
            InventoryItem::where(fn ($q) => $q
                ->whereBetween('warranty_expires_on', [$startStr, $endStr])
                ->orWhereBetween('return_by', [$startStr, $endStr])
            )
                ->get(['id', 'name', 'warranty_expires_on', 'return_by'])
                ->flatMap(function ($i) use ($startStr, $endStr) {
                    $events = [];
                    if ($i->warranty_expires_on && $i->warranty_expires_on->between($startStr, $endStr)) {
                        $events[] = [
                            'date' => $i->warranty_expires_on->toDateString(),
                            'time' => null,
                            'title' => $i->name.' — '.__('warranty ends'),
                            'type' => 'inventory',
                            'id' => $i->id,
                            'class' => 'bg-amber-900/40 text-amber-200 border-amber-800/40',
                        ];
                    }
                    if ($i->return_by && $i->return_by->between($startStr, $endStr)) {
                        $events[] = [
                            'date' => $i->return_by->toDateString(),
                            'time' => null,
                            'title' => $i->name.' — '.__('return by'),
                            'type' => 'inventory',
                            'id' => $i->id,
                            'class' => 'bg-rose-900/40 text-rose-200 border-rose-800/40',
                        ];
                    }

                    return $events;
                })
        );

        $all = $all->merge(
            Vehicle::whereNotNull('registration_expires_on')
                ->whereBetween('registration_expires_on', [$startStr, $endStr])
                ->get(['id', 'make', 'model', 'license_plate', 'registration_expires_on'])
                ->map(fn ($v) => [
                    'date' => $v->registration_expires_on?->toDateString(),
                    'time' => null,
                    'title' => trim(($v->make ?? '').' '.($v->model ?? '')).' — '.__('registration'),
                    'type' => 'vehicle',
                    'id' => $v->id,
                    'class' => 'bg-amber-900/40 text-amber-200 border-amber-800/40',
                ])
        );

        $all = $all->merge(
            Account::whereIn('type', ['gift_card', 'prepaid'])
                ->where('is_active', true)
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [$startStr, $endStr])
                ->get(['id', 'name', 'expires_on'])
                ->map(fn ($a) => [
                    'date' => $a->expires_on?->toDateString(),
                    'time' => null,
                    'title' => $a->name.' — '.__('expires'),
                    'type' => 'account',
                    'id' => $a->id,
                    'class' => 'bg-amber-900/40 text-amber-200 border-amber-800/40',
                ])
        );

        $all = $all->merge(
            Prescription::whereNotNull('next_refill_on')
                ->whereBetween('next_refill_on', [$startStr, $endStr])
                ->get(['id', 'name', 'next_refill_on'])
                ->map(fn ($p) => [
                    'date' => $p->next_refill_on?->toDateString(),
                    'time' => null,
                    'title' => $p->name.' — '.__('refill'),
                    'type' => 'prescription',
                    'id' => $p->id,
                    'class' => 'bg-cyan-900/40 text-cyan-200 border-cyan-800/40',
                ])
        );

        // Sort within each day: timed events first (by time), then untimed.
        return $all
            ->filter(fn ($e) => $e['date'] !== null)
            ->groupBy('date')
            ->map(fn (Collection $g) => $g->sortBy(fn ($e) => ($e['time'] ?? 'z')))
            ->sortKeys();
    }
};
?>

<div class="space-y-5">
    @php
        $anchor = $this->anchor();
        $rangeStart = $this->rangeStart();
        $rangeEnd = $this->rangeEnd();
        $today = now()->toDateString();
        $events = $this->events;
        $inspectableTypes = ['task', 'meeting', 'contract', 'inventory', 'vehicle', 'account', 'bill', 'document', 'appointment'];

        // Week-strip header names — generated once per render from the grid's first weekday.
        $weekdayNames = [];
        $wd = $rangeStart;
        for ($i = 0; $i < 7; $i++) {
            $weekdayNames[] = $wd->isoFormat('dd');
            $wd = $wd->addDay();
        }

        // Header title adapts to the view: month shows "April 2026", week shows "Apr 13 – 19",
        // day shows "Monday, Apr 20".
        $title = match ($this->view) {
            'day' => $anchor->isoFormat('dddd, MMM D'),
            'week' => $rangeStart->isoFormat('MMM D').' – '.$rangeEnd->isoFormat('MMM D'),
            default => $anchor->startOfMonth()->isoFormat('MMMM YYYY'),
        };
        $prevLabel = match ($this->view) {
            'day' => __('Previous day'),
            'week' => __('Previous week'),
            default => __('Previous month'),
        };
        $nextLabel = match ($this->view) {
            'day' => __('Next day'),
            'week' => __('Next week'),
            default => __('Next month'),
        };
    @endphp

    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Calendar') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Every date-bearing record, on one grid.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- View switcher --}}
            <div class="inline-flex rounded-md border border-neutral-700 bg-neutral-900 p-0.5" role="tablist" aria-label="{{ __('Calendar view') }}">
                @foreach (['month' => __('Month'), 'week' => __('Week'), 'day' => __('Day')] as $v => $label)
                    <button type="button" role="tab" aria-selected="{{ $this->view === $v ? 'true' : 'false' }}"
                            wire:click="setView('{{ $v }}')"
                            class="rounded px-2 py-1 text-xs transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $this->view === $v ? 'bg-neutral-700 text-neutral-100' : 'text-neutral-400 hover:text-neutral-200' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <button type="button" wire:click="go(-1)" aria-label="{{ $prevLabel }}"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-sm text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                ‹
            </button>
            <div class="min-w-[14ch] text-center text-sm font-medium tabular-nums text-neutral-100">
                {{ $title }}
            </div>
            <button type="button" wire:click="go(1)" aria-label="{{ $nextLabel }}"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-sm text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                ›
            </button>
            <button type="button" wire:click="today"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1 text-xs text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Today') }}
            </button>
        </div>
    </header>

    @if ($this->view === 'month')
        <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
            <div class="grid grid-cols-7 border-b border-neutral-800 bg-neutral-900/60 text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                @foreach ($weekdayNames as $name)
                    <div class="px-2 py-1.5 text-center">{{ $name }}</div>
                @endforeach
            </div>
            <div class="grid grid-cols-7 divide-x divide-y divide-neutral-800">
                @for ($i = 0; $i < 42; $i++)
                    @php
                        $day = $rangeStart->addDays($i);
                        $dayStr = $day->toDateString();
                        $isCurrentMonth = $day->month === $anchor->startOfMonth()->month;
                        $isToday = $dayStr === $today;
                        $dayEvents = $events->get($dayStr) ?? collect();
                    @endphp
                    <div class="min-h-[88px] p-1.5 {{ $isCurrentMonth ? 'bg-neutral-950/40' : 'bg-neutral-900/30 text-neutral-600' }}">
                        <div class="flex items-baseline justify-between gap-1">
                            <span class="text-[11px] tabular-nums {{ $isToday ? 'flex h-5 w-5 items-center justify-center rounded-full bg-neutral-100 font-medium text-neutral-900' : ($isCurrentMonth ? 'text-neutral-300' : 'text-neutral-600') }}">
                                {{ $day->day }}
                            </span>
                            @if ($dayEvents->count() > 3)
                                <span class="text-[10px] text-neutral-500">+{{ $dayEvents->count() - 3 }}</span>
                            @endif
                        </div>
                        @if ($dayEvents->isNotEmpty())
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($dayEvents->take(3) as $e)
                                    @php $canOpen = in_array($e['type'], $inspectableTypes, true); @endphp
                                    <li>
                                        @if ($canOpen)
                                            <button type="button"
                                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => $e['type'], 'id' => $e['id']]) }})"
                                                    class="flex w-full items-center gap-1 overflow-hidden rounded border px-1 py-0.5 text-left text-[10px] leading-tight transition hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $e['class'] }}">
                                                @if ($e['time'])
                                                    <span class="shrink-0 font-mono text-[10px] opacity-70">{{ $e['time'] }}</span>
                                                @endif
                                                <span class="truncate">{{ $e['title'] }}</span>
                                            </button>
                                        @else
                                            <span class="flex items-center gap-1 overflow-hidden rounded border px-1 py-0.5 text-[10px] leading-tight {{ $e['class'] }}">
                                                @if ($e['time'])
                                                    <span class="shrink-0 font-mono text-[10px] opacity-70">{{ $e['time'] }}</span>
                                                @endif
                                                <span class="truncate">{{ $e['title'] }}</span>
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endfor
            </div>
        </div>
    @elseif ($this->view === 'week')
        {{-- Week: 7 columns, taller cells, no event truncation. Same cell renderer as month
             but without the "previous-month fade" since every day is in-range. --}}
        <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
            <div class="grid grid-cols-7 border-b border-neutral-800 bg-neutral-900/60 text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                @for ($i = 0; $i < 7; $i++)
                    @php $d = $rangeStart->addDays($i); @endphp
                    <div class="px-2 py-1.5 text-center">
                        <div>{{ $d->isoFormat('dd') }}</div>
                        <div class="mt-0.5 text-[11px] text-neutral-300 {{ $d->toDateString() === $today ? 'font-semibold text-neutral-50' : '' }}">{{ $d->day }}</div>
                    </div>
                @endfor
            </div>
            <div class="grid grid-cols-7 divide-x divide-neutral-800">
                @for ($i = 0; $i < 7; $i++)
                    @php
                        $day = $rangeStart->addDays($i);
                        $dayStr = $day->toDateString();
                        $isToday = $dayStr === $today;
                        $dayEvents = $events->get($dayStr) ?? collect();
                    @endphp
                    <div class="min-h-[320px] p-2 {{ $isToday ? 'bg-neutral-900/70' : 'bg-neutral-950/40' }}">
                        @if ($dayEvents->isEmpty())
                            <p class="mt-3 text-center text-[10px] text-neutral-600">{{ __('Nothing') }}</p>
                        @else
                            <ul class="space-y-1">
                                @foreach ($dayEvents as $e)
                                    @php $canOpen = in_array($e['type'], $inspectableTypes, true); @endphp
                                    <li>
                                        @if ($canOpen)
                                            <button type="button"
                                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => $e['type'], 'id' => $e['id']]) }})"
                                                    class="flex w-full items-start gap-1.5 overflow-hidden rounded border px-1.5 py-1 text-left text-[11px] leading-tight transition hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $e['class'] }}">
                                                @if ($e['time'])
                                                    <span class="shrink-0 font-mono text-[10px] opacity-70">{{ $e['time'] }}</span>
                                                @endif
                                                <span class="break-words">{{ $e['title'] }}</span>
                                            </button>
                                        @else
                                            <span class="flex items-start gap-1.5 overflow-hidden rounded border px-1.5 py-1 text-[11px] leading-tight {{ $e['class'] }}">
                                                @if ($e['time'])
                                                    <span class="shrink-0 font-mono text-[10px] opacity-70">{{ $e['time'] }}</span>
                                                @endif
                                                <span class="break-words">{{ $e['title'] }}</span>
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endfor
            </div>
        </div>
    @else
        {{-- Day: single column split into "all-day" (events without a time) and a timed section.
             No hour rail — events with times sort chronologically and the time is prefixed. --}}
        @php
            $dayEvents = $events->get($anchor->toDateString()) ?? collect();
            $allDay = $dayEvents->filter(fn ($e) => empty($e['time']));
            $timed = $dayEvents->filter(fn ($e) => ! empty($e['time']));
        @endphp
        <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
            <div class="border-b border-neutral-800 bg-neutral-900/60 px-4 py-2 text-xs font-medium text-neutral-300">
                {{ $anchor->isoFormat('dddd · MMMM D, YYYY') }}
                @if ($anchor->toDateString() === $today)
                    <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold text-neutral-900">{{ __('Today') }}</span>
                @endif
            </div>
            <div class="divide-y divide-neutral-800">
                @if ($dayEvents->isEmpty())
                    <div class="px-4 py-8 text-center text-sm text-neutral-500">
                        {{ __('No events on this day.') }}
                    </div>
                @else
                    @if ($allDay->isNotEmpty())
                        <section class="px-4 py-3">
                            <h3 class="mb-2 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('All day') }}</h3>
                            <ul class="space-y-1">
                                @foreach ($allDay as $e)
                                    @php $canOpen = in_array($e['type'], $inspectableTypes, true); @endphp
                                    <li>
                                        @if ($canOpen)
                                            <button type="button"
                                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => $e['type'], 'id' => $e['id']]) }})"
                                                    class="inline-block rounded border px-2 py-1 text-left text-xs transition hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $e['class'] }}">
                                                {{ $e['title'] }}
                                            </button>
                                        @else
                                            <span class="inline-block rounded border px-2 py-1 text-xs {{ $e['class'] }}">{{ $e['title'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                    @if ($timed->isNotEmpty())
                        <section class="px-4 py-3">
                            <h3 class="mb-2 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Scheduled') }}</h3>
                            <ul class="space-y-1.5">
                                @foreach ($timed as $e)
                                    @php $canOpen = in_array($e['type'], $inspectableTypes, true); @endphp
                                    <li class="flex items-start gap-3">
                                        <span class="mt-0.5 w-12 shrink-0 font-mono text-xs text-neutral-400 tabular-nums">{{ $e['time'] }}</span>
                                        @if ($canOpen)
                                            <button type="button"
                                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => $e['type'], 'id' => $e['id']]) }})"
                                                    class="flex-1 rounded border px-2 py-1 text-left text-xs transition hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $e['class'] }}">
                                                {{ $e['title'] }}
                                            </button>
                                        @else
                                            <span class="flex-1 rounded border px-2 py-1 text-xs {{ $e['class'] }}">{{ $e['title'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
