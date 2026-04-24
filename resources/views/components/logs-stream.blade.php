<?php

use App\Models\BodyMeasurement;
use App\Models\Decision;
use App\Models\FoodEntry;
use App\Models\JournalEntry;
use App\Models\MediaLogEntry;
use App\Models\TimeEntry;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Merged timeline across all six log domains. Structured tables stay
 * the source of truth; this view just interleaves their rows by date
 * and exposes a single scroll so the user can read a day in one pass
 * without tabbing between journal / decisions / food / etc.
 *
 * Each row links back to the inspector for its original type, so an
 * edit always lands on the domain form with full fields.
 */
new class extends Component
{
    #[Url(as: 'days', except: '30')]
    public int $windowDays = 30;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->entries);
    }

    /**
     * Each domain emits rows of the shape
     *   ['type' => string, 'id' => int, 'at' => CarbonImmutable, 'label' => string]
     * then we interleave by at desc and cap for the current window.
     *
     * @return Collection<int, array{type: string, id: int, at: CarbonImmutable, label: string}>
     */
    #[Computed]
    public function entries(): Collection
    {
        $since = CarbonImmutable::today()->subDays(max(1, $this->windowDays));
        $items = collect();

        JournalEntry::where('occurred_on', '>=', $since->toDateString())
            ->get(['id', 'occurred_on', 'title', 'body'])
            ->each(function ($j) use ($items) {
                $label = (string) ($j->title ?: trim((string) str($j->body)->limit(80)->toString()));
                if ($label === '') {
                    $label = '(' . __('empty entry') . ')';
                }
                $items->push([
                    'type' => 'journal_entry',
                    'id' => (int) $j->id,
                    'at' => CarbonImmutable::parse($j->occurred_on)->startOfDay(),
                    'label' => $label,
                ]);
            });

        Decision::where('decided_on', '>=', $since->toDateString())
            ->get(['id', 'decided_on', 'title'])
            ->each(fn ($d) => $items->push([
                'type' => 'decision',
                'id' => (int) $d->id,
                'at' => CarbonImmutable::parse($d->decided_on)->startOfDay(),
                'label' => (string) $d->title,
            ]));

        MediaLogEntry::query()
            ->where(function ($q) use ($since) {
                $q->where('started_on', '>=', $since->toDateString())
                    ->orWhere('finished_on', '>=', $since->toDateString());
            })
            ->get(['id', 'started_on', 'finished_on', 'title'])
            ->each(function ($m) use ($items) {
                // Prefer finished_on as the event timestamp (the moment it
                // became a record); fall back to started_on.
                $when = $m->finished_on ?? $m->started_on;
                if ($when === null) {
                    return;
                }
                $items->push([
                    'type' => 'media_log_entry',
                    'id' => (int) $m->id,
                    'at' => CarbonImmutable::parse($when)->startOfDay(),
                    'label' => (string) $m->title,
                ]);
            });

        FoodEntry::where('eaten_at', '>=', $since->startOfDay())
            ->get(['id', 'eaten_at', 'label'])
            ->each(fn ($f) => $items->push([
                'type' => 'food_entry',
                'id' => (int) $f->id,
                'at' => CarbonImmutable::parse($f->eaten_at),
                'label' => (string) $f->label,
            ]));

        $trimNum = static fn ($v): string => rtrim(rtrim((string) $v, '0'), '.');
        BodyMeasurement::where('measured_at', '>=', $since->startOfDay())
            ->get(['id', 'measured_at', 'weight_kg', 'body_fat_pct', 'muscle_pct'])
            ->each(function ($b) use ($items, $trimNum) {
                $bits = [];
                if ($b->weight_kg !== null) {
                    $bits[] = $trimNum($b->weight_kg).' kg';
                }
                if ($b->body_fat_pct !== null) {
                    $bits[] = $trimNum($b->body_fat_pct).'% fat';
                }
                if ($b->muscle_pct !== null) {
                    $bits[] = $trimNum($b->muscle_pct).'% muscle';
                }
                $items->push([
                    'type' => 'body_measurement',
                    'id' => (int) $b->id,
                    'at' => CarbonImmutable::parse($b->measured_at),
                    'label' => $bits === [] ? __('measurement') : implode(' · ', $bits),
                ]);
            });

        TimeEntry::where('started_at', '>=', $since->startOfDay())
            ->with(['project:id,name', 'task:id,title'])
            ->get(['id', 'started_at', 'description', 'project_id', 'task_id'])
            ->each(function ($t) use ($items) {
                $label = trim((string) $t->description);
                if ($label === '') {
                    $label = (string) ($t->task?->title ?? $t->project?->name ?? __('time entry'));
                }
                $items->push([
                    'type' => 'time_entry',
                    'id' => (int) $t->id,
                    'at' => CarbonImmutable::parse($t->started_at),
                    'label' => $label,
                ]);
            });

        return $items
            ->sortByDesc(fn ($row) => $row['at']->timestamp)
            ->values();
    }

    /**
     * Entries grouped by local date (Y-m-d) for the day-header render.
     * Returns an ordered map of dateKey → rows, dates descending.
     *
     * @return array<string, Collection<int, array{type: string, id: int, at: CarbonImmutable, label: string}>>
     */
    #[Computed]
    public function entriesByDate(): array
    {
        $out = [];
        foreach ($this->entries as $row) {
            $key = $row['at']->toDateString();
            $out[$key] = $out[$key] ?? collect();
            $out[$key]->push($row);
        }

        return $out;
    }
};
?>

@php
    $chipClass = static fn (string $type): string => match ($type) {
        'journal_entry' => 'bg-indigo-900/30 text-indigo-300',
        'decision' => 'bg-amber-900/30 text-amber-300',
        'media_log_entry' => 'bg-fuchsia-900/30 text-fuchsia-300',
        'food_entry' => 'bg-emerald-900/30 text-emerald-300',
        'body_measurement' => 'bg-sky-900/30 text-sky-300',
        'time_entry' => 'bg-neutral-800 text-neutral-400',
        default => 'bg-neutral-800 text-neutral-400',
    };
    $chipLabel = static fn (string $type): string => match ($type) {
        'journal_entry' => __('journal'),
        'decision' => __('decision'),
        'media_log_entry' => __('read/watch'),
        'food_entry' => __('food'),
        'body_measurement' => __('body'),
        'time_entry' => __('time'),
        default => $type,
    };
@endphp

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-3">
        <div>
            <h2 class="text-sm font-medium text-neutral-200">{{ __('Daily stream') }}</h2>
            <p class="mt-0.5 text-xs text-neutral-500">{{ __('Every log type, interleaved by date. Click a row to edit in place.') }}</p>
        </div>
        <div class="flex items-center gap-1.5 text-xs">
            <label for="logs-stream-days" class="text-neutral-500">{{ __('Window') }}</label>
            <select wire:model.live="windowDays" id="logs-stream-days"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="7">7 {{ __('days') }}</option>
                <option value="30">30 {{ __('days') }}</option>
                <option value="90">90 {{ __('days') }}</option>
                <option value="365">1 {{ __('year') }}</option>
            </select>
        </div>
    </header>

    @if (count($this->entriesByDate) === 0)
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('Nothing logged in the last :n days.', ['n' => $windowDays]) }}
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->entriesByDate as $dateKey => $rows)
                @php($dayLabel = Formatting::date($dateKey))
                <section aria-labelledby="day-{{ $dateKey }}-h">
                    <header class="mb-1.5 flex items-baseline gap-3 border-b border-neutral-800/60 pb-1">
                        <h3 id="day-{{ $dateKey }}-h" class="text-xs font-medium uppercase tracking-wider text-neutral-400">
                            {{ $dayLabel }}
                        </h3>
                        <span class="text-[10px] text-neutral-500 tabular-nums">{{ $rows->count() }}</span>
                    </header>
                    <ul class="divide-y divide-neutral-800/60 rounded-xl border border-neutral-800 bg-neutral-900/40">
                        @foreach ($rows as $row)
                            <li>
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', { type: '{{ $row['type'] }}', id: {{ $row['id'] }} })"
                                        class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider {{ $chipClass($row['type']) }}">
                                        {{ $chipLabel($row['type']) }}
                                    </span>
                                    <span class="flex-1 min-w-0 truncate text-neutral-100">{{ $row['label'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
