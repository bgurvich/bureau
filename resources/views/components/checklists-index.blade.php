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
 * Checklists index — two tabs:
 *   - Templates list (default): name, bucket, item count, streak, last-run status.
 *   - History grid (?tab=history): per-template 60-day completion heat-strip.
 *
 * Both tabs link to the today page for ticking, and to the Inspector for
 * template editing.
 */
new
#[Layout('components.layouts.app', ['title' => 'Checklists'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('checklists', $this->tab, 'templates');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['templates', 'history'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('checklists', $tab);
        }
    }

    private const HISTORY_DAYS = 60;

    /** @return Collection<int, ChecklistTemplate> */
    #[Computed]
    public function templates(): Collection
    {
        return ChecklistTemplate::with(['items' => fn ($q) => $q->orderBy('position')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Today's run per template, keyed by template id. Used to show a
     * "done / pending / skipped" chip inline.
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

    /** @return array<int, int> template_id → streak */
    #[Computed]
    public function streaks(): array
    {
        $out = [];
        foreach ($this->templates as $t) {
            $out[$t->id] = ChecklistScheduling::streak($t);
        }

        return $out;
    }

    /**
     * Last 60 days of run rows per template, keyed by template id then by
     * date string. Missing keys = no run that day.
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

        $rows = ChecklistRun::where('run_date', '>=', $start)
            ->orderBy('run_date')
            ->get();

        $out = [];
        foreach ($rows as $run) {
            $date = CarbonImmutable::parse($run->run_date)->toDateString();
            $out[$run->checklist_template_id][$date] = $run;
        }

        return $out;
    }

    /** @return array<int, string> list of YYYY-MM-DD strings oldest → newest */
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

    #[On('inspector-saved')]
    public function onInspectorSaved(): void
    {
        unset($this->templates, $this->todayRuns, $this->streaks, $this->history);
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Checklists')"
        :description="__('Recurring ritual templates — morning routine, evening wind-down, custom flows. Ticks are tracked per day.')">
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

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Checklist views') }}">
        @foreach (['templates' => __('Templates'), 'history' => __('History')] as $key => $label)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    @if ($tab === $key) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $tab === $key ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    @if ($this->templates->isEmpty())
        <x-ui.empty-state>
            {{ __('No checklist templates yet. Create one (e.g. "Morning routine") to start tracking.') }}
        </x-ui.empty-state>
    @elseif ($tab === 'templates')
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
                            {{ $this->streaks[$t->id] ?? 0 }}
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
    @else
        @php $dates = $this->historyDates(); @endphp
        <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/50">
            <table class="w-full min-w-[900px] text-xs">
                <thead>
                    <tr class="border-b border-neutral-800 bg-neutral-900/60">
                        <th class="sticky left-0 z-10 bg-neutral-900/90 px-3 py-2 text-left font-medium text-neutral-500">{{ __('Checklist') }}</th>
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
                    @foreach ($this->templates as $t)
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
                                    <a href="{{ route('life.checklists.today', ['date' => $d]) }}"
                                       aria-label="{{ $label }} — {{ $d }}"
                                       title="{{ $label }} · {{ $d }}"
                                       class="inline-block h-4 w-4 rounded-sm {{ $klass }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></a>
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
</div>
