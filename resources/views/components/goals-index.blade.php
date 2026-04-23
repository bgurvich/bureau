<?php

use App\Models\Goal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Goals'])]
class extends Component
{
    /** '' | 'active' | 'paused' | 'achieved' | 'abandoned' */
    #[Url(as: 'status')]
    public string $statusFilter = 'active';

    /** '' | 'target' | 'direction' */
    #[Url(as: 'mode')]
    public string $modeFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->goals, $this->statusCounts, $this->modeCounts, $this->linkedTaskCounts);
    }

    /** @return Collection<int, Goal> */
    #[Computed]
    public function goals(): Collection
    {
        /** @var Collection<int, Goal> $list */
        $list = Goal::query()
            ->with('user:id,name')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->modeFilter !== '', fn ($q) => $q->where('mode', $this->modeFilter))
            ->orderByRaw("FIELD(status, 'active', 'paused', 'achieved', 'abandoned')")
            ->orderByDesc('target_date')
            ->orderByDesc('id')
            ->get();

        return $list;
    }

    /**
     * Linked-task progress per goal: [goal_id => [total, done]]. Single
     * grouped query joining task_subjects → tasks; keeps the listing's
     * per-row "3 of 5 done" cheap.
     *
     * @return array<int, array{total: int, done: int}>
     */
    #[Computed]
    public function linkedTaskCounts(): array
    {
        $goalIds = $this->goals->pluck('id')->all();
        if ($goalIds === []) {
            return [];
        }

        $rows = DB::table('task_subjects')
            ->join('tasks', 'tasks.id', '=', 'task_subjects.task_id')
            ->where('task_subjects.subject_type', Goal::class)
            ->whereIn('task_subjects.subject_id', $goalIds)
            ->selectRaw('task_subjects.subject_id as goal_id, COUNT(*) as total, SUM(CASE WHEN tasks.state = ? THEN 1 ELSE 0 END) as done', ['done'])
            ->groupBy('task_subjects.subject_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->goal_id] = [
                'total' => (int) $row->total,
                'done' => (int) $row->done,
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    #[Computed]
    public function statusCounts(): array
    {
        return [
            'active' => Goal::where('status', 'active')->count(),
            'paused' => Goal::where('status', 'paused')->count(),
            'achieved' => Goal::where('status', 'achieved')->count(),
            'abandoned' => Goal::where('status', 'abandoned')->count(),
        ];
    }

    /** @return array<string, int> */
    #[Computed]
    public function modeCounts(): array
    {
        return [
            'target' => Goal::where('mode', 'target')->count(),
            'direction' => Goal::where('mode', 'direction')->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Goals')"
        :description="__('Targets to hit and directions to stay on. Link tasks and projects to tether the work to the why.')">
        <x-ui.new-record-button type="goal" :label="__('New goal')" />
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</span>
        @foreach ([
            'active' => __('Active'),
            'paused' => __('Paused'),
            'achieved' => __('Achieved'),
            'abandoned' => __('Abandoned'),
        ] as $v => $l)
            @php
                $count = $this->statusCounts[$v] ?? 0;
                $active = $this->statusFilter === $v;
            @endphp
            <button type="button"
                    wire:click="$set('statusFilter', '{{ $active ? '' : $v }}')"
                    class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }} <span class="ml-1 text-neutral-500">· {{ $count }}</span>
            </button>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Mode') }}</span>
        @foreach ([
            'target' => __('Targets'),
            'direction' => __('Directions'),
        ] as $v => $l)
            @php
                $count = $this->modeCounts[$v] ?? 0;
                $active = $this->modeFilter === $v;
            @endphp
            <button type="button"
                    wire:click="$set('modeFilter', '{{ $active ? '' : $v }}')"
                    class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }} <span class="ml-1 text-neutral-500">· {{ $count }}</span>
            </button>
        @endforeach
    </div>

    @if ($this->goals->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No goals yet. Pick a target or name a direction.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->goals as $g)
                @php
                    $isTarget = $g->mode === 'target';
                    $pct = $isTarget ? (int) round($g->progress() * 100) : null;
                    $onTrack = $isTarget ? $g->onTrack() : null;
                    $stale = ! $isTarget ? $g->isStale() : null;
                    $linked = $this->linkedTaskCounts[$g->id] ?? null;
                    $cat = $g->category;
                @endphp
                <x-ui.inspector-row type="goal" :id="$g->id" :label="$g->title" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="font-medium text-neutral-100 {{ $g->status === 'achieved' ? 'line-through opacity-70' : '' }}">{{ $g->title }}</span>
                            <span class="rounded border border-neutral-800 bg-neutral-950 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-neutral-500">
                                {{ \App\Support\Enums::goalCategories()[$cat] ?? $cat }}
                            </span>
                            @if (! $isTarget)
                                <span class="rounded border border-sky-900 bg-sky-950/40 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-sky-300">{{ __('direction') }}</span>
                            @endif
                        </div>

                        @if ($isTarget)
                            <div class="flex items-center gap-3">
                                <div class="h-1.5 w-40 shrink-0 overflow-hidden rounded-full bg-neutral-800">
                                    <div class="h-full rounded-full
                                                {{ $onTrack === true ? 'bg-emerald-400' : ($onTrack === false ? 'bg-amber-400' : 'bg-neutral-500') }}"
                                         style="width: {{ max(2, $pct) }}%"></div>
                                </div>
                                <span class="tabular-nums text-[11px] text-neutral-400">
                                    {{ rtrim(rtrim(number_format((float) $g->current_value, 2), '0'), '.') }}
                                    /
                                    {{ rtrim(rtrim(number_format((float) $g->target_value, 2), '0'), '.') }}
                                    @if ($g->unit) <span class="text-neutral-500">{{ $g->unit }}</span>@endif
                                    <span class="ml-1 text-neutral-500">· {{ $pct }}%</span>
                                </span>
                                @if ($onTrack === true)
                                    <span class="text-[11px] text-emerald-400">{{ __('on track') }}</span>
                                @elseif ($onTrack === false)
                                    <span class="text-[11px] text-amber-400">{{ __('behind pace') }}</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($g->target_date)
                                    <span>{{ __('by :d', ['d' => \App\Support\Formatting::date($g->target_date)]) }}</span>
                                @endif
                                @if ($g->achieved_on)
                                    <span class="text-emerald-400">{{ __('achieved :d', ['d' => \App\Support\Formatting::date($g->achieved_on)]) }}</span>
                                @endif
                                @if ($linked)
                                    <span>{{ __(':d of :t linked tasks done', ['d' => $linked['done'], 't' => $linked['total']]) }}</span>
                                @endif
                                @if ($g->user)<span>— {{ $g->user->name }}</span>@endif
                            </div>
                        @else
                            <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($g->last_reflected_at)
                                    <span class="{{ $stale ? 'text-amber-400' : '' }}">
                                        {{ __('last reflected :when', ['when' => $g->last_reflected_at->diffForHumans(['parts' => 1, 'short' => true])]) }}
                                    </span>
                                @else
                                    <span class="text-neutral-400">{{ __('no reflections yet') }}</span>
                                @endif
                                @if ($g->cadence_days)
                                    <span>{{ __('every :n days', ['n' => $g->cadence_days]) }}</span>
                                @endif
                                @if ($stale)
                                    <span class="text-amber-400">{{ __('time for a check-in') }}</span>
                                @endif
                                @if ($linked)
                                    <span>{{ __(':d of :t linked tasks done', ['d' => $linked['done'], 't' => $linked['total']]) }}</span>
                                @endif
                                @if ($g->user)<span>— {{ $g->user->name }}</span>@endif
                            </div>
                        @endif
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>
