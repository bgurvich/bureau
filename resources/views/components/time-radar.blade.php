<?php

use App\Models\Meeting;
use App\Models\RecurringProjection;
use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function upcoming(): Collection
    {
        $horizon = now()->addDays(7);

        $tasks = Task::where('state', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $horizon)
            ->orderBy('due_at')
            ->limit(8)
            ->get()
            ->map(fn ($t) => [
                'kind' => 'task',
                'when' => $t->due_at,
                'label' => $t->title,
            ]);

        $meetings = Meeting::where('starts_at', '>=', now()->startOfDay())
            ->where('starts_at', '<=', $horizon)
            ->orderBy('starts_at')
            ->limit(8)
            ->get()
            ->map(fn ($m) => [
                'kind' => 'meeting',
                'when' => $m->starts_at,
                'label' => $m->title,
            ]);

        $bills = RecurringProjection::whereBetween('due_on', [now()->toDateString(), $horizon->toDateString()])
            ->whereIn('status', ['projected', 'matched', 'overdue'])
            ->with('rule')
            ->orderBy('due_on')
            ->limit(8)
            ->get()
            ->map(fn ($p) => [
                'kind' => 'bill',
                'when' => $p->due_on,
                'label' => $p->rule?->title ?? 'Bill',
            ]);

        return collect()
            ->concat($tasks)->concat($meetings)->concat($bills)
            ->sortBy(fn ($e) => $e['when'] instanceof \DateTimeInterface ? $e['when']->getTimestamp() : strtotime($e['when']))
            ->values();
    }

    #[Computed]
    public function todayCount(): int
    {
        $today = now()->startOfDay();
        $tomorrow = now()->endOfDay();
        return $this->upcoming->filter(function ($e) use ($today, $tomorrow) {
            $when = $e['when'];
            $ts = $when instanceof \DateTimeInterface ? $when : \Carbon\Carbon::parse($when);
            return $ts->between($today, $tomorrow);
        })->count();
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Time · next 7 days</h3>
        <span class="text-xs text-neutral-500 tabular-nums">{{ $this->todayCount }} today</span>
    </div>

    @if ($this->upcoming->isEmpty())
        <div class="py-6 text-center text-xs text-neutral-600">Nothing on the horizon.</div>
    @else
        <ul class="space-y-2 text-sm">
            @foreach ($this->upcoming->take(6) as $event)
                <li class="flex items-baseline justify-between gap-3">
                    <div class="flex min-w-0 items-baseline gap-2">
                        <span class="shrink-0 text-xs uppercase tracking-wider {{ $event['kind'] === 'task' ? 'text-amber-400' : ($event['kind'] === 'meeting' ? 'text-sky-400' : 'text-violet-400') }}">
                            {{ $event['kind'] }}
                        </span>
                        <span class="truncate text-neutral-200">{{ $event['label'] }}</span>
                    </div>
                    <span class="shrink-0 text-xs tabular-nums text-neutral-500">
                        @php $when = $event['when'] instanceof \DateTimeInterface ? $event['when'] : \Carbon\Carbon::parse($event['when']); @endphp
                        {{ $when->diffForHumans(['parts' => 1, 'short' => true]) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
