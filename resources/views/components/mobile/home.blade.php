<?php

use App\Models\Appointment;
use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Meeting;
use App\Models\RecurringProjection;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Transaction;
use App\Support\ChecklistScheduling;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Mobile quick-glance dashboard. Optimised for "standing in line reading the
 * phone" — big numbers, minimum cognitive load. Each card links to the
 * matching desktop-grade surface for deeper interaction. Keep the data
 * footprint tight; under ~5 queries total.
 */
new
#[Layout('components.layouts.mobile', ['title' => 'Home'])]
class extends Component
{
    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    #[Computed]
    public function overdueTasks(): int
    {
        return Task::where('state', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    #[Computed]
    public function overdueBills(): int
    {
        $todayStr = now()->toDateString();
        $graceCutoff = now()->subDays(7)->toDateString();

        return RecurringProjection::whereIn('status', ['overdue', 'projected'])
            ->where('due_on', '<', $todayStr)
            ->where(fn ($q) => $q
                ->where('autopay', false)
                ->orWhere(fn ($i) => $i->where('autopay', true)->where('due_on', '<', $graceCutoff))
            )
            ->count();
    }

    #[Computed]
    public function dueReminders(): int
    {
        return Reminder::where('state', 'pending')
            ->where('remind_at', '<=', now())
            ->count();
    }

    #[Computed]
    public function inboxCount(): int
    {
        $media = Media::whereNull('processed_at')
            ->where('ocr_status', 'done')
            ->whereNotNull('ocr_extracted')
            ->count();
        $inventory = InventoryItem::whereNull('processed_at')->count();

        return $media + $inventory;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function todayItems(): Collection
    {
        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $tasks = Task::where('state', 'open')
            ->whereBetween('due_at', [$startOfDay, $endOfDay])
            ->orderBy('due_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'kind' => 'task',
                'title' => $t->title,
                'at' => $t->due_at,
                'route' => 'calendar.tasks',
            ]);

        $meetings = Meeting::whereBetween('starts_at', [$startOfDay, $endOfDay])
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'kind' => 'meeting',
                'title' => $m->title ?? __('Meeting'),
                'at' => $m->starts_at,
                'route' => 'calendar.meetings',
            ]);

        $appointments = Appointment::whereBetween('starts_at', [$startOfDay, $endOfDay])
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'kind' => 'appointment',
                'title' => $a->purpose ?? __('Appointment'),
                'at' => $a->starts_at,
                'route' => 'health.appointments',
            ]);

        return $tasks->concat($meetings)->concat($appointments)
            ->sortBy('at')
            ->values();
    }

    /**
     * @return array{in: float, out: float, net: float}
     */
    #[Computed]
    public function thisMonth(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $in = (float) Transaction::where('amount', '>', 0)
            ->whereBetween('occurred_on', [$start, $end])
            ->sum('amount');
        $out = (float) Transaction::where('amount', '<', 0)
            ->whereBetween('occurred_on', [$start, $end])
            ->sum('amount');

        return [
            'in' => $in,
            'out' => $out,
            'net' => $in + $out,
        ];
    }

    /**
     * Today's scheduled templates with their current run (if any), keyed
     * by template id, with pre-computed done/total counts for the mobile
     * tile. Tapping an item toggles it in place; no navigation.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function todaysRituals(): Collection
    {
        $templates = ChecklistScheduling::templatesScheduledOn(now());
        if ($templates->isEmpty()) {
            return collect();
        }

        $ids = $templates->pluck('id')->all();
        $runs = ChecklistRun::whereIn('checklist_template_id', $ids)
            ->where('run_date', now()->toDateString())
            ->get()
            ->keyBy('checklist_template_id');

        return $templates->map(function ($t) use ($runs) {
            $run = $runs->get($t->id);
            $active = $t->items->where('active', true)->values();
            $ticked = $run ? array_map('intval', $run->tickedIds()) : [];
            $done = count(array_intersect($ticked, $active->pluck('id')->map(fn ($i) => (int) $i)->all()));

            return [
                'template' => $t,
                'items' => $active,
                'ticked' => $ticked,
                'done' => $done,
                'total' => $active->count(),
                'complete' => $run && $run->completed_at !== null,
                'skipped' => $run && $run->skipped_at !== null,
            ];
        });
    }

    public function toggleRitualItem(int $templateId, int $itemId): void
    {
        $template = ChecklistTemplate::with('items')->find($templateId);
        if (! $template || ! $template->isScheduledOn(now())) {
            return;
        }
        $activeIds = $template->activeItemIds();
        if (! in_array($itemId, $activeIds, true)) {
            return;
        }

        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => now()->toDateString(),
        ]);

        if (in_array($itemId, $run->tickedIds(), true)) {
            $run->untick($itemId);
            $run->completed_at = null;
        } else {
            $run->tick($itemId);
            if ($activeIds !== [] && array_diff($activeIds, $run->tickedIds()) === []) {
                $run->completed_at = now();
            }
        }
        if ($run->skipped_at) {
            $run->skipped_at = null;
        }
        $run->save();

        unset($this->todaysRituals);
    }

    /**
     * @return array{count: int, amount: float}
     */
    #[Computed]
    public function upcoming7d(): array
    {
        $todayStr = now()->toDateString();
        $weekStr = now()->addDays(7)->toDateString();

        $rows = RecurringProjection::whereIn('status', ['projected', 'overdue'])
            ->whereBetween('due_on', [$todayStr, $weekStr])
            ->get(['amount']);

        return [
            'count' => $rows->count(),
            'amount' => (float) $rows->sum('amount'),
        ];
    }
};
?>

<div class="space-y-4">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Home') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ now()->format('l, M j') }}</p>
    </header>

    {{-- Alerts row --}}
    <section aria-labelledby="home-alerts" class="space-y-2">
        <h2 id="home-alerts" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Needs attention') }}</h2>
        <div class="grid grid-cols-2 gap-2">
            <a href="{{ route('calendar.tasks') }}"
               class="block rounded-xl border border-neutral-800 bg-neutral-900/60 p-3 hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Overdue tasks') }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums {{ $this->overdueTasks > 0 ? 'text-rose-400' : 'text-neutral-300' }}">
                    {{ $this->overdueTasks }}
                </div>
            </a>
            <a href="{{ route('fiscal.recurring') }}"
               class="block rounded-xl border border-neutral-800 bg-neutral-900/60 p-3 hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Overdue bills') }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums {{ $this->overdueBills > 0 ? 'text-rose-400' : 'text-neutral-300' }}">
                    {{ $this->overdueBills }}
                </div>
            </a>
            <div class="rounded-xl border border-neutral-800 bg-neutral-900/60 p-3">
                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Reminders due') }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums {{ $this->dueReminders > 0 ? 'text-amber-300' : 'text-neutral-300' }}">
                    {{ $this->dueReminders }}
                </div>
            </div>
            <a href="{{ route('mobile.inbox') }}"
               class="block rounded-xl border border-neutral-800 bg-neutral-900/60 p-3 hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Inbox') }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums {{ $this->inboxCount > 0 ? 'text-sky-300' : 'text-neutral-300' }}">
                    {{ $this->inboxCount }}
                </div>
            </a>
        </div>
    </section>

    {{-- Today row --}}
    <section aria-labelledby="home-today" class="space-y-2">
        <h2 id="home-today" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Today') }}</h2>
        @if ($this->todayItems->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-4 text-center text-xs text-neutral-500">
                {{ __('Nothing scheduled.') }}
            </div>
        @else
            <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->todayItems as $it)
                    <li>
                        <a href="{{ route($it['route']) }}" class="flex items-baseline justify-between gap-3 px-3 py-2 text-sm hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                            <div class="min-w-0 flex-1">
                                <span class="mr-2 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $it['kind'] }}</span>
                                <span class="text-neutral-100">{{ $it['title'] }}</span>
                            </div>
                            <span class="shrink-0 text-[11px] tabular-nums text-neutral-500">{{ $it['at']?->format('H:i') ?? '' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Rituals row --}}
    @if ($this->todaysRituals->isNotEmpty())
        <section aria-labelledby="home-rituals" class="space-y-2">
            <h2 id="home-rituals" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                <span>{{ __('Rituals') }}</span>
                <a href="{{ route('life.checklists.today') }}" class="text-neutral-500 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Open →') }}</a>
            </h2>
            <div class="space-y-2">
                @foreach ($this->todaysRituals as $r)
                    @php $t = $r['template']; @endphp
                    <div wire:key="mh-ritual-{{ $t->id }}"
                         class="rounded-xl border border-neutral-800 bg-neutral-900/60 p-3 {{ $r['complete'] || $r['skipped'] ? 'opacity-70' : '' }}">
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-medium text-neutral-100">{{ $t->name }}</span>
                            <span class="text-[11px] tabular-nums text-neutral-500">{{ $r['done'] }}/{{ $r['total'] }}</span>
                        </div>
                        @if ($r['total'] > 0 && ! $r['skipped'])
                            <ul class="mt-2 space-y-1">
                                @foreach ($r['items'] as $item)
                                    @php $on = in_array((int) $item->id, $r['ticked'], true); @endphp
                                    <li>
                                        <button type="button"
                                                wire:click="toggleRitualItem({{ $t->id }}, {{ $item->id }})"
                                                class="flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-sm {{ $on ? 'text-neutral-500 line-through' : 'text-neutral-100 hover:bg-neutral-800/40' }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            <span aria-hidden="true"
                                                  class="inline-flex h-4 w-4 items-center justify-center rounded border {{ $on ? 'border-emerald-500 bg-emerald-500/30 text-emerald-200' : 'border-neutral-700 bg-neutral-950' }}">
                                                @if ($on)
                                                    <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none">
                                                        <path d="M2 6l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                @endif
                                            </span>
                                            <span>{{ $item->label }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Money row --}}
    <section aria-labelledby="home-money" class="space-y-2">
        <h2 id="home-money" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('This month') }}</h2>
        <a href="{{ route('fiscal.overview') }}" class="block rounded-xl border border-neutral-800 bg-neutral-900/60 p-3 hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <div class="grid grid-cols-3 gap-3 text-center">
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('In') }}</div>
                    <div class="mt-1 text-sm tabular-nums text-emerald-400">+{{ Formatting::money($this->thisMonth['in'], $this->currency) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Out') }}</div>
                    <div class="mt-1 text-sm tabular-nums text-rose-400">{{ Formatting::money($this->thisMonth['out'], $this->currency) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Net') }}</div>
                    <div class="mt-1 text-sm tabular-nums {{ $this->thisMonth['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ ($this->thisMonth['net'] >= 0 ? '+' : '').Formatting::money($this->thisMonth['net'], $this->currency) }}
                    </div>
                </div>
            </div>
        </a>
    </section>

    {{-- Upcoming bills --}}
    <section aria-labelledby="home-upcoming" class="space-y-2">
        <h2 id="home-upcoming" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Next 7 days') }}</h2>
        <a href="{{ route('fiscal.recurring') }}" class="block rounded-xl border border-neutral-800 bg-neutral-900/60 p-3 hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <div class="flex items-baseline justify-between">
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Bills coming due') }}</div>
                    <div class="mt-1 text-sm text-neutral-100">{{ trans_choice(':n bill|:n bills', $this->upcoming7d['count'], ['n' => $this->upcoming7d['count']]) }}</div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Cumulative') }}</div>
                    <div class="mt-1 text-sm tabular-nums {{ $this->upcoming7d['amount'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ Formatting::money($this->upcoming7d['amount'], $this->currency) }}
                    </div>
                </div>
            </div>
        </a>
    </section>
</div>
