<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Support\ChecklistScheduling;
use App\Support\HubTabMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Checklists hub — three tabs over the same ChecklistTemplate set:
 *   - Today: habit-style cards (recurring templates only), one-click
 *     toggle to extend streak.
 *   - All: table of every template with a type filter
 *     (All / Habits / One-offs) — the management view. One-offs
 *     (shopping, packing, onboarding) surface here.
 *   - History: 60-day completion heat-strip across all habits.
 *
 * Habit vs one-off is derived from the rrule (COUNT=1 → one-off, any
 * other recurring rrule → habit) — no dedicated column, no separate
 * toggle. Just change the recurrence dropdown in the template form to
 * flip a row's classification.
 */
new
#[Layout('components.layouts.app', ['title' => 'Checklists'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    /** '' = all, 'habit' = recurring, 'one_off' = non-recurring. */
    #[Url(as: 'type')]
    public string $typeFilter = '';

    private const HISTORY_DAYS = 60;

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('checklists', $this->tab, 'today');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['today', 'all', 'history'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('checklists', $tab);
        }
    }

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset(
            $this->templates,
            $this->todayRuns,
            $this->streaks,
            $this->habits,
            $this->history,
        );
    }

    /** All templates, filtered by $typeFilter for the All tab. */
    #[Computed]
    public function templates(): Collection
    {
        /** @var Collection<int, ChecklistTemplate> $rows */
        $rows = ChecklistTemplate::with(['items' => fn ($q) => $q->orderBy('position')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($this->typeFilter === 'habit') {
            $rows = $rows->filter(fn ($t) => $t->isHabit())->values();
        } elseif ($this->typeFilter === 'one_off') {
            $rows = $rows->filter(fn ($t) => $t->isOneOff())->values();
        }

        return $rows;
    }

    /** Recurring (habit) templates only — for the Today + History tabs.
     *  Items eager-loaded so Today can render per-item checkboxes. */
    #[Computed]
    public function habits(): Collection
    {
        /** @var Collection<int, ChecklistTemplate> $list */
        $list = ChecklistTemplate::query()
            ->with(['items' => fn ($q) => $q->where('active', true)->orderBy('position')])
            ->whereNotNull('rrule')
            ->where('rrule', '!=', '')
            ->where('rrule', 'not like', '%COUNT=1%')
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $list;
    }

    /**
     * Today's run per template, keyed by template id. Used by both
     * the Today cards and the All tab's per-row state chip.
     *
     * @return array<int, ChecklistRun>
     */
    #[Computed]
    public function todayRuns(): array
    {
        $today = now()->toDateString();

        return ChecklistRun::where('run_date', $today)
            ->get()
            ->keyBy('checklist_template_id')
            ->all();
    }

    /**
     * Streak per template id. Walked from the current template set so
     * both the Today + All tabs read off the same map.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function streaks(): array
    {
        $out = [];
        $rows = $this->tab === 'today' ? $this->habits : $this->templates;
        foreach ($rows as $t) {
            $out[$t->id] = ChecklistScheduling::streak($t);
        }

        return $out;
    }

    /** One-click "I did it today" on the Today tab. For habits with
     *  items this stamps all items done at once — useful when the
     *  user wants to skip the granular ticking for a routine that went
     *  exactly as planned. */
    public function toggleToday(int $templateId): void
    {
        $template = ChecklistTemplate::find($templateId);
        if (! $template || ! $template->isHabit()) {
            return;
        }

        $today = CarbonImmutable::today()->toDateString();
        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => $today,
        ]);

        if ($run->completed_at !== null) {
            $run->completed_at = null;
        } else {
            $run->ticked_item_ids = $template->activeItemIds();
            $run->completed_at = now();
            $run->skipped_at = null;
        }
        $run->save();

        $this->refresh();
    }

    /**
     * Tick / untick a single item on a habit's today run. completed_at
     * auto-stamps when every active item is ticked, and clears when any
     * active item becomes unticked again.
     */
    public function toggleItem(int $templateId, int $itemId): void
    {
        $template = ChecklistTemplate::with('items')->find($templateId);
        if (! $template || ! $template->isHabit()) {
            return;
        }
        $activeItemIds = $template->activeItemIds();
        if (! in_array($itemId, $activeItemIds, true)) {
            return;
        }

        $today = CarbonImmutable::today()->toDateString();
        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => $today,
        ]);

        $current = $run->tickedIds();
        if (in_array($itemId, $current, true)) {
            $run->untick($itemId);
            $run->completed_at = null;
        } else {
            $run->tick($itemId);
            if ($activeItemIds !== [] && array_diff($activeItemIds, $run->tickedIds()) === []) {
                $run->completed_at = now();
            }
        }
        if ($run->skipped_at) {
            $run->skipped_at = null;
        }
        $run->save();

        $this->refresh();
    }

    /**
     * Last 60 days of runs per template. Loaded only when History tab
     * is active to keep Today / All cheap.
     *
     * @return array<int, array<string, ChecklistRun>>
     */
    #[Computed]
    public function history(): array
    {
        if ($this->tab !== 'history') {
            return [];
        }

        $start = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay()->toDateString();
        $habitIds = $this->habits->pluck('id')->all();
        if ($habitIds === []) {
            return [];
        }

        $rows = ChecklistRun::whereIn('checklist_template_id', $habitIds)
            ->where('run_date', '>=', $start)
            ->orderBy('run_date')
            ->get();

        $out = [];
        foreach ($rows as $run) {
            $date = CarbonImmutable::parse($run->run_date)->toDateString();
            $out[$run->checklist_template_id][$date] = $run;
        }

        return $out;
    }

    /** @return array<int, string> YYYY-MM-DD strings oldest → newest */
    public function historyDates(): array
    {
        $out = [];
        $start = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();
        for ($i = 0; $i < self::HISTORY_DAYS; $i++) {
            $out[] = $start->copy()->addDays($i)->toDateString();
        }

        return $out;
    }

    public function cellState(ChecklistTemplate $t, string $dateStr): string
    {
        $run = $this->history[$t->id][$dateStr] ?? null;
        $scheduled = ChecklistScheduling::isScheduledOn($t, $dateStr);

        if ($run && $run->completed_at) {
            return 'complete';
        }
        if ($run && $run->skipped_at) {
            return 'skipped';
        }
        if ($run && ! empty($run->tickedIds())) {
            return 'partial';
        }
        if ($scheduled) {
            return 'missed';
        }

        return 'unscheduled';
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Checklists')"
        :description="__('Habits, routines, and one-off lists (shopping, packing, onboarding) — all the same underlying template, grouped by how you use them.')">
        <a href="{{ route('life.checklists.today') }}"
           class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-200 hover:border-neutral-600 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Today\'s rituals') }} →
        </a>
        <button type="button"
                wire:click="$dispatch('inspector-open', { type: 'checklist_template' })"
                class="rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('New checklist') }}
        </button>
    </x-ui.page-header>

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Checklists views') }}">
        @foreach ([
            'today' => __('Today'),
            'all' => __('All'),
            'history' => __('History'),
        ] as $key => $label)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    @if ($tab === $key) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $tab === $key ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'today')
        @if ($this->habits->isEmpty())
            <x-ui.empty-state>
                {{ __('No habits yet. Create one with any recurring cadence — daily, weekdays, weekly, custom.') }}
            </x-ui.empty-state>
        @else
            <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach ($this->habits as $habit)
                    @php
                        $run = $this->todayRuns[$habit->id] ?? null;
                        $doneToday = $run !== null && $run->completed_at !== null;
                        $scheduledToday = $habit->isScheduledOn(CarbonImmutable::today());
                        $streak = $this->streaks[$habit->id] ?? 0;
                        $tickedIds = $run ? $run->tickedIds() : [];
                        $items = $habit->items->where('active', true)->values();
                        $activeItemCount = $items->count();
                        $tickedCount = count(array_intersect($items->pluck('id')->all(), $tickedIds));
                    @endphp
                    <li class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4">
                        <div class="flex items-start gap-3">
                            {{-- Big "all-done" toggle. Useful for single-item habits
                                 (meditate 10 min) or for batch-marking a multi-item
                                 routine when the user doesn't want to tick each item. --}}
                            <button type="button"
                                    wire:click="toggleToday({{ $habit->id }})"
                                    aria-pressed="{{ $doneToday ? 'true' : 'false' }}"
                                    aria-label="{{ $doneToday ? __('Mark :name as undone for today', ['name' => $habit->name]) : __('Mark :name as done for today', ['name' => $habit->name]) }}"
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300
                                           {{ $doneToday ? 'border-emerald-500 bg-emerald-500/20 text-emerald-400' : ($scheduledToday ? 'border-neutral-500 text-transparent hover:border-neutral-300' : 'border-neutral-800 text-transparent') }}">
                                <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                    <path d="M3 8l3.5 3.5L13 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', { type: 'checklist_template', id: {{ $habit->id }} })"
                                    class="min-w-0 flex-1 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <div class="truncate text-sm font-medium text-neutral-100">{{ $habit->name }}</div>
                                @if ($habit->description)
                                    <div class="mt-0.5 truncate text-[11px] text-neutral-500">{{ $habit->description }}</div>
                                @endif
                                <div class="mt-1 flex items-center gap-3 text-[11px] text-neutral-500">
                                    @if ($streak > 0)
                                        <span class="rounded bg-amber-950/40 px-1.5 py-0.5 font-mono text-amber-300"
                                              title="{{ __(':n day streak', ['n' => $streak]) }}">
                                            {{ __(':n d', ['n' => $streak]) }}
                                        </span>
                                    @endif
                                    @if (! $scheduledToday)
                                        <span>{{ __('not scheduled today') }}</span>
                                    @elseif ($doneToday)
                                        <span class="text-emerald-400">{{ __('done today') }}</span>
                                    @elseif ($activeItemCount > 0)
                                        <span class="tabular-nums">{{ $tickedCount }}/{{ $activeItemCount }}</span>
                                    @else
                                        <span>{{ __('pending today') }}</span>
                                    @endif
                                </div>
                            </button>
                        </div>

                        {{-- Per-item checkboxes. Only render when the habit has more
                             than one active item — single-item habits rely on the big
                             toggle above. --}}
                        @if ($activeItemCount > 1)
                            <ul class="mt-3 space-y-1 border-t border-neutral-800/60 pt-2">
                                @foreach ($items as $item)
                                    @php
                                        $ticked = in_array((int) $item->id, $tickedIds, true);
                                    @endphp
                                    <li>
                                        <label class="flex items-start gap-2 rounded px-1 py-0.5 text-xs hover:bg-neutral-800/30">
                                            <button type="button"
                                                    wire:click="toggleItem({{ $habit->id }}, {{ $item->id }})"
                                                    aria-pressed="{{ $ticked ? 'true' : 'false' }}"
                                                    aria-label="{{ $ticked ? __('Untick :label', ['label' => $item->label]) : __('Tick :label', ['label' => $item->label]) }}"
                                                    class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300
                                                           {{ $ticked ? 'border-emerald-500/60 bg-emerald-500/20 text-emerald-400' : 'border-neutral-600 text-transparent hover:border-neutral-400' }}">
                                                <svg class="h-2.5 w-2.5" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                    <path d="M2.5 6.2 5 8.7l4.5-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </button>
                                            <span class="flex-1 {{ $ticked ? 'text-neutral-500 line-through' : 'text-neutral-200' }}">{{ $item->label }}</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @elseif ($tab === 'all')
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Type') }}</span>
            @foreach ([
                '' => __('All'),
                'habit' => __('Habits'),
                'one_off' => __('One-offs'),
            ] as $v => $l)
                @php $active = $typeFilter === $v; @endphp
                <button type="button"
                        wire:click="$set('typeFilter', '{{ $v }}')"
                        class="rounded-md border px-2 py-1 text-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                    {{ $l }}
                </button>
            @endforeach
        </div>

        @if ($this->templates->isEmpty())
            <x-ui.empty-state>
                {{ __('No checklist templates yet. Create one (e.g. "Morning routine") to start tracking.') }}
            </x-ui.empty-state>
        @else
            <x-ui.data-table>
                <thead class="border-b border-neutral-800 bg-neutral-900/60">
                    <tr>
                        <th class="px-3 py-2 text-left text-[10px] uppercase tracking-wider font-medium text-neutral-500">{{ __('Checklist') }}</th>
                        <th class="px-3 py-2 text-left text-[10px] uppercase tracking-wider font-medium text-neutral-500">{{ __('Time') }}</th>
                        <th class="px-3 py-2 text-right text-[10px] uppercase tracking-wider font-medium text-neutral-500">{{ __('Items') }}</th>
                        <th class="px-3 py-2 text-right text-[10px] uppercase tracking-wider font-medium text-neutral-500">{{ __('Streak') }}</th>
                        <th class="px-3 py-2 text-left text-[10px] uppercase tracking-wider font-medium text-neutral-500">{{ __('Today') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800">
                    @foreach ($this->templates as $t)
                        @php
                            $activeCount = $t->items->where('active', true)->count();
                            $run = $this->todayRuns[$t->id] ?? null;
                            $scheduledToday = $t->isScheduledOn(now());
                        @endphp
                        <tr wire:key="cl-row-{{ $t->id }}"
                            class="cursor-pointer hover:bg-neutral-800/30 {{ ! $t->active ? 'opacity-60' : '' }}"
                            wire:click="$dispatch('inspector-open', { type: 'checklist_template', id: {{ $t->id }} })">
                            <td class="px-3 py-2 text-neutral-100">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $t->name }}</span>
                                    @if ($t->isOneOff())
                                        <span class="rounded-sm bg-sky-950/40 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-sky-300">{{ __('one-off') }}</span>
                                    @endif
                                    @if (! $t->active)
                                        <x-ui.row-badge state="paused">{{ __('inactive') }}</x-ui.row-badge>
                                    @elseif ($t->paused_until)
                                        <x-ui.row-badge state="paused">{{ __('paused') }}</x-ui.row-badge>
                                    @endif
                                </div>
                                @if ($t->description)
                                    <div class="text-[11px] text-neutral-500">{{ \Illuminate\Support\Str::limit($t->description, 80) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-neutral-400 capitalize">{{ $t->time_of_day }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-neutral-300">{{ $activeCount }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ ($this->streaks[$t->id] ?? 0) > 0 ? 'text-emerald-300' : 'text-neutral-500' }}">
                                {{ $t->isHabit() ? ($this->streaks[$t->id] ?? 0) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-[11px]">
                                @if (! $scheduledToday)
                                    <span class="text-neutral-600">{{ __('not today') }}</span>
                                @elseif ($run && $run->completed_at)
                                    <span class="text-emerald-300">{{ __('done') }}</span>
                                @elseif ($run && $run->skipped_at)
                                    <span class="text-neutral-500">{{ __('skipped') }}</span>
                                @elseif ($run)
                                    <span class="text-amber-300">{{ count($run->tickedIds()) }}/{{ $activeCount }}</span>
                                @else
                                    <span class="text-neutral-400">{{ __('pending') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.data-table>
        @endif
    @else
        @if ($this->habits->isEmpty())
            <x-ui.empty-state>
                {{ __('No habits yet — no history to show.') }}
            </x-ui.empty-state>
        @else
            @php $dates = $this->historyDates(); @endphp
            <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/50">
                <table class="w-full min-w-[900px] text-xs">
                    <thead>
                        <tr class="border-b border-neutral-800 bg-neutral-900/60">
                            <th class="sticky left-0 z-10 bg-neutral-900/90 px-3 py-2 text-left font-medium text-neutral-500">{{ __('Habit') }}</th>
                            @foreach ($dates as $d)
                                @php $day = CarbonImmutable::parse($d); @endphp
                                <th class="px-0.5 py-2 text-center font-normal text-neutral-600 {{ $day->isToday() ? 'text-neutral-200' : '' }}"
                                    title="{{ $day->toFormattedDateString() }}">
                                    <div class="text-[10px] leading-none">{{ $day->format('j') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-800">
                        @foreach ($this->habits as $t)
                            <tr wire:key="cl-hist-{{ $t->id }}">
                                <td class="sticky left-0 z-10 bg-neutral-900/90 px-3 py-2 text-neutral-200">
                                    <button type="button"
                                            wire:click="$dispatch('inspector-open', { type: 'checklist_template', id: {{ $t->id }} })"
                                            class="text-left hover:text-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ $t->name }}
                                    </button>
                                </td>
                                @foreach ($dates as $d)
                                    @php
                                        $state = $this->cellState($t, $d);
                                        $klass = match ($state) {
                                            'complete' => 'bg-emerald-500/70',
                                            'partial' => 'bg-amber-500/60',
                                            'skipped' => 'bg-neutral-700',
                                            'missed' => 'bg-neutral-800',
                                            default => 'bg-transparent',
                                        };
                                        $label = match ($state) {
                                            'complete' => __('Completed'),
                                            'partial' => __('Partial'),
                                            'skipped' => __('Skipped'),
                                            'missed' => __('Missed'),
                                            default => __('Not scheduled'),
                                        };
                                    @endphp
                                    <td class="px-0.5 py-1 text-center">
                                        <span aria-label="{{ $label }} — {{ $d }}"
                                              title="{{ $label }} · {{ $d }}"
                                              class="inline-block h-4 w-4 rounded-sm {{ $klass }}"></span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-[11px] text-neutral-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-emerald-500/70"></span>{{ __('Completed') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-amber-500/60"></span>{{ __('Partial') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-neutral-700"></span>{{ __('Skipped') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-neutral-800"></span>{{ __('Missed') }}</span>
            </div>
        @endif
    @endif
</div>
