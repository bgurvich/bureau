<?php

use App\Models\Task;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Top-nav tasks dropdown. Surface area: the next ≤10 open tasks sorted
 * by priority (1→5) then due_at (nulls last). Intentionally distinct
 * from alerts-bell — alerts surfaces *overdue* + *acute* across bills,
 * pets, birthdays; this list answers the simpler "what should I pick
 * up next?" question.
 *
 * Badge = count of open tasks that are overdue OR due today, so the
 * signal stays about acuity even though the list shows more than that.
 */
new class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->topTasks, $this->acuteCount, $this->openCount);
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function topTasks(): Collection
    {
        return Task::where('state', 'open')
            ->with('project:id,name')
            ->orderBy('priority')
            ->orderByRaw('due_at IS NULL, due_at')
            ->limit(10)
            ->get(['id', 'title', 'priority', 'due_at', 'project_id']);
    }

    #[Computed]
    public function acuteCount(): int
    {
        $endOfToday = now()->endOfDay();

        return Task::where('state', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $endOfToday)
            ->count();
    }

    #[Computed]
    public function openCount(): int
    {
        return Task::where('state', 'open')->count();
    }
};
?>

<div x-data="{
        open: false,
        items() { return [...this.$el.querySelectorAll('[data-task-item]')]; },
        focusFirst() { this.$nextTick(() => this.items()[0]?.focus()); },
        move(delta) {
            const items = this.items();
            if (items.length === 0) return;
            const active = document.activeElement;
            const idx = items.indexOf(active);
            const next = idx === -1 ? 0 : (idx + delta + items.length) % items.length;
            items[next]?.focus();
        },
     }"
     @keydown.escape.window="open = false"
     @click.outside="open = false"
     @keydown.arrow-down.prevent="if (open) move(1)"
     @keydown.arrow-up.prevent="if (open) move(-1)"
     class="relative">
    <button type="button"
            @click="open = !open; if (open) focusFirst()"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="{{ __('Tasks') }}"
            class="relative flex h-8 w-8 items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 text-neutral-300 hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 11l3 3L22 4"/>
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        @if ($this->acuteCount > 0)
            <span aria-hidden="true"
                  class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold leading-none text-neutral-950 tabular-nums">
                {{ $this->acuteCount > 99 ? '99+' : $this->acuteCount }}
            </span>
        @endif
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.100ms
        role="menu"
        aria-label="{{ __('Tasks') }}"
        class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-md border border-neutral-800 bg-neutral-900 shadow-xl"
    >
        <header class="border-b border-neutral-800 px-4 py-2.5">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-neutral-100">{{ __('Tasks') }}</span>
                <div class="flex items-center gap-2">
                    <span class="text-[11px] text-neutral-500 tabular-nums">{{ __(':n open', ['n' => $this->openCount]) }}</span>
                    <button type="button"
                            @click="open = false; $dispatch('tasks-bulk-open')"
                            aria-label="{{ __('Bulk add tasks') }}"
                            title="{{ __('Bulk add tasks') }}"
                            class="inline-flex h-6 items-center gap-1 rounded border border-neutral-700 px-2 text-[11px] font-medium text-neutral-300 hover:border-neutral-500 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span aria-hidden="true">+</span>
                        <span>{{ __('Bulk') }}</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="max-h-96 overflow-y-auto">
            @if ($this->topTasks->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-neutral-500">
                    {{ __('Nothing open — inbox zero.') }}
                </div>
            @else
                <ul class="py-1">
                    @foreach ($this->topTasks as $t)
                        @php
                            $dueAt = $t->due_at;
                            $isOverdue = $dueAt && $dueAt->isPast() && ! $dueAt->isToday();
                            $isToday = $dueAt && $dueAt->isToday();
                            $dueColor = $isOverdue ? 'text-rose-400' : ($isToday ? 'text-amber-400' : 'text-neutral-500');
                            $prioBg = match ((int) $t->priority) {
                                1 => 'bg-rose-900/40 text-rose-300',
                                2 => 'bg-amber-900/40 text-amber-300',
                                default => 'bg-neutral-800 text-neutral-400',
                            };
                        @endphp
                        <li>
                            <button type="button"
                                    data-task-item
                                    @click="open = false"
                                    wire:click="$dispatch('inspector-open', { type: 'task', id: {{ $t->id }} })"
                                    class="flex w-full items-baseline gap-2 px-4 py-1.5 text-left text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider {{ $prioBg }}">P{{ $t->priority }}</span>
                                <span class="flex-1 min-w-0">
                                    <span class="block truncate text-neutral-200">{{ $t->title }}</span>
                                    @if ($t->project)
                                        <span class="block truncate text-[11px] text-neutral-500">{{ $t->project->name }}</span>
                                    @endif
                                </span>
                                @if ($dueAt)
                                    <span class="shrink-0 text-[11px] tabular-nums {{ $dueColor }}">{{ Formatting::date($dueAt) }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <footer class="border-t border-neutral-800 bg-neutral-950/50 px-4 py-2 text-[11px]">
            <a href="{{ route('calendar.tasks') }}"
               @click="open = false"
               class="text-neutral-400 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Open tasks page →') }}
            </a>
        </footer>
    </div>
</div>
