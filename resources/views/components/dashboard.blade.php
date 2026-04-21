<?php

use App\Models\ChecklistRun;
use App\Models\TimeEntry;
use App\Support\ChecklistScheduling;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Dashboard'])]
class extends Component
{
    #[Computed]
    public function todayHours(): float
    {
        $seconds = TimeEntry::where('user_id', auth()->id())
            ->where('activity_date', now()->toDateString())
            ->sum('duration_seconds');
        return round($seconds / 3600, 2);
    }

    #[Computed]
    public function weekHours(): float
    {
        $seconds = TimeEntry::where('user_id', auth()->id())
            ->whereBetween('activity_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
            ->sum('duration_seconds');
        return round($seconds / 3600, 2);
    }

    /**
     * Rituals scheduled for today with per-template progress. Returns an
     * empty collection if the user has no active templates — the tile is
     * hidden entirely in that case (no "empty" dashboard card).
     *
     * @return Collection<int, array{template: \App\Models\ChecklistTemplate, done: int, total: int, state: string}>
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
            $active = $t->items->where('active', true);
            $activeIds = $active->pluck('id')->map(fn ($i) => (int) $i)->all();
            $ticked = $run ? array_map('intval', $run->tickedIds()) : [];
            $done = count(array_intersect($activeIds, $ticked));
            $state = match (true) {
                $run && $run->completed_at !== null => 'complete',
                $run && $run->skipped_at !== null => 'skipped',
                $run && $done > 0 => 'partial',
                default => 'pending',
            };

            return [
                'template' => $t,
                'done' => $done,
                'total' => max(1, $active->count()),
                'state' => $state,
            ];
        });
    }
};
?>

<div class="space-y-5">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <livewire:money-radar />
        <livewire:time-radar />
        <livewire:commitments-radar />
        <livewire:documents-radar />
        <livewire:attention-radar />

        @if ($this->todaysRituals->isNotEmpty())
            <div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
                <div class="mb-4 flex items-baseline justify-between">
                    <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __("Today's rituals") }}</h3>
                    <a href="{{ route('life.checklists.today') }}" class="text-xs text-neutral-500 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Open →') }}</a>
                </div>
                <ul class="space-y-2 text-sm">
                    @foreach ($this->todaysRituals as $r)
                        @php
                            $pct = (int) floor(($r['done'] / $r['total']) * 100);
                            $bar = match ($r['state']) {
                                'complete' => 'bg-emerald-500/80',
                                'skipped' => 'bg-neutral-600',
                                'partial' => 'bg-amber-400/80',
                                default => 'bg-neutral-700',
                            };
                        @endphp
                        <li wire:key="dash-ritual-{{ $r['template']->id }}">
                            <a href="{{ route('life.checklists.today') }}"
                               class="block rounded-md px-1 py-1 hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-neutral-200">{{ $r['template']->name }}</span>
                                    <span class="shrink-0 text-[11px] tabular-nums text-neutral-500">{{ $r['done'] }}/{{ $r['total'] }}</span>
                                </div>
                                <div class="mt-1 h-1 overflow-hidden rounded-full bg-neutral-800">
                                    <div class="h-full {{ $bar }}" style="width: {{ $pct }}%"></div>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
            <div class="mb-4 flex items-baseline justify-between">
                <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Time tracker</h3>
                <a href="{{ route('time.entries') }}" class="text-xs text-neutral-500 hover:text-neutral-300">All →</a>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-xs text-neutral-500">Today</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ number_format($this->todayHours, 2) }}h</div>
                </div>
                <div>
                    <div class="text-xs text-neutral-500">This week</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ number_format($this->weekHours, 2) }}h</div>
                </div>
            </div>
            <p class="mt-4 text-xs text-neutral-500">Start a timer from the top bar; stopped timers round up to the next 5 minutes and land in your log.</p>
        </div>
    </div>
</div>
