<?php

use App\Models\Task;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Tasks'])]
class extends Component
{
    #[Url(as: 'state')]
    public string $stateFilter = 'open';

    #[Url(as: 'priority')]
    public string $priorityFilter = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function toggle(int $id): void
    {
        $t = Task::find($id);
        if (! $t) {
            return;
        }

        if ($t->state === 'done') {
            $t->update(['state' => 'open', 'completed_at' => null]);
        } else {
            $t->update(['state' => 'done', 'completed_at' => now()]);
        }
    }

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->tasks, $this->counts);
    }

    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->with(['assignedUser:id,name', 'tags:id,name,slug'])
            ->when($this->stateFilter !== '', fn ($q) => $q->where('state', $this->stateFilter))
            ->when($this->priorityFilter !== '', fn ($q) => $q->where('priority', (int) $this->priorityFilter))
            ->when($this->tagFilter !== '', fn ($q) => $q
                ->whereHas('tags', fn ($t) => $t->where('slug', $this->tagFilter))
            )
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                );
            })
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', ['open'])
            ->orderBy('priority')
            ->orderByRaw('due_at IS NULL, due_at')
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $today = CarbonImmutable::today();

        return [
            'overdue' => Task::where('state', 'open')
                ->whereNotNull('due_at')
                ->where('due_at', '<', $today->toDateTimeString())
                ->count(),
            'today' => Task::where('state', 'open')
                ->whereBetween('due_at', [$today->startOfDay(), $today->endOfDay()])
                ->count(),
            'open' => Task::where('state', 'open')->count(),
            'done' => Task::where('state', 'done')->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Tasks') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Open work, sorted by priority and due date.') }}</p>
        </div>
        <x-ui.new-record-button type="task" :label="__('New task')" shortcut="T" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Overdue') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['overdue'] > 0 ? 'text-rose-400' : 'text-neutral-500' }}">{{ $this->counts['overdue'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Today') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['today'] > 0 ? 'text-amber-400' : 'text-neutral-500' }}">{{ $this->counts['today'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Open') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['open'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Done') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-500">{{ $this->counts['done'] }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="t-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="t-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Title or description…') }}">
        </div>
        <div>
            <label for="t-state" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('State') }}</label>
            <select wire:model.live="stateFilter" id="t-state"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::taskStates() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="t-prio" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Priority') }}</label>
            <select wire:model.live="priorityFilter" id="t-prio"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                <option value="1">1 — {{ __('High') }}</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5 — {{ __('Low') }}</option>
            </select>
        </div>
    </form>

    @if ($tagFilter !== '')
        <div role="status" class="flex items-center justify-between rounded-lg border border-emerald-800/40 bg-emerald-900/20 px-4 py-2 text-sm text-emerald-200">
            <span class="font-mono">{{ __('Filtering by') }} #{{ $tagFilter }}</span>
            <button type="button" wire:click="$set('tagFilter', '')"
                    class="rounded-md px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Clear') }}
            </button>
        </div>
    @endif

    @if ($this->tasks->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No tasks match those filters.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->tasks as $task)
                @php
                    $isDone = $task->state === 'done';
                    $dueAt = $task->due_at ? CarbonImmutable::parse($task->due_at) : null;
                    $isOverdue = ! $isDone && $dueAt && $dueAt->isPast();
                    $isDueToday = ! $isDone && $dueAt && $dueAt->isToday();
                @endphp
                <li class="flex items-start gap-3 px-4 py-3 text-sm transition hover:bg-neutral-800/30">
                    <button
                        type="button"
                        wire:click="toggle({{ $task->id }})"
                        aria-label="{{ $isDone ? __('Mark as open') : __('Mark as done') }}"
                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border transition
                               {{ $isDone ? 'border-emerald-500/50 bg-emerald-500/20 text-emerald-400' : 'border-neutral-600 text-transparent hover:border-neutral-400' }}
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                    >
                        <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M2.5 6.2 5 8.7l4.5-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'task', id: {{ $task->id }} })"
                            class="min-w-0 flex-1 cursor-pointer text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="flex items-baseline gap-2">
                            <span class="{{ $isDone ? 'text-neutral-500 line-through' : 'text-neutral-100' }} truncate">{{ $task->title }}</span>
                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wider
                                         {{ match ((int) $task->priority) {
                                            1 => 'bg-rose-900/30 text-rose-300',
                                            2 => 'bg-amber-900/30 text-amber-300',
                                            default => 'bg-neutral-800 text-neutral-500',
                                         } }}">
                                P{{ $task->priority }}
                            </span>
                        </div>
                        @if ($task->description)
                            <div class="mt-0.5 truncate text-xs text-neutral-500">{{ $task->description }}</div>
                        @endif
                        <div class="mt-1 flex flex-wrap items-center gap-3 text-[11px] text-neutral-500">
                            @if ($dueAt)
                                <span class="{{ $isOverdue ? 'text-rose-400' : ($isDueToday ? 'text-amber-400' : '') }}">
                                    {{ __('Due') }} {{ Formatting::date($dueAt) }}
                                </span>
                            @endif
                            @if ($task->state !== 'open' && $task->state !== 'done')
                                <span class="uppercase tracking-wider">{{ $task->state }}</span>
                            @endif
                            @if ($task->assignedUser)
                                <span>{{ $task->assignedUser->name }}</span>
                            @endif
                        </div>
                        <x-ui.tag-chips :tags="$task->tags" :active="$tagFilter" class="mt-1" />
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
