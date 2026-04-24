<?php

use App\Models\Project;
use App\Models\Task;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Hierarchical view: one section per Project, with its tasks nested
 * under parent→subtask. A synthetic "Unassigned" bucket captures
 * tasks with project_id = null.
 *
 * Subject chips + tag chips render inline so the user can scan
 * context without opening the inspector. Priority renders as a
 * colored dot before the title (not a separate chip) so the row
 * stays compact.
 */
new
#[Layout('components.layouts.app', ['title' => 'Tasks tree'])]
class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->projects, $this->tasks);
    }

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
        unset($this->projects, $this->tasks);
    }

    /**
     * Persist a drop: rewrite project_id + position for every task
     * in the target group to the sequence the DOM committed. Tasks
     * left behind in the source group keep their positions; gaps
     * are fine since ORDER BY position is monotonic either way.
     *
     * Only top-level tasks (parent_task_id=null) participate in the
     * tree drag surface, so this doesn't accidentally strand a
     * subtask under a different project than its parent.
     *
     * @param  array<int, int>  $taskIds
     */
    public function moveToGroup(string $groupKey, array $taskIds): void
    {
        $projectId = null;
        if (str_starts_with($groupKey, 'project:')) {
            $projectId = (int) substr($groupKey, 8);
        } elseif ($groupKey !== 'unassigned') {
            return;
        }
        if ($projectId !== null && ! Project::where('id', $projectId)->exists()) {
            return;
        }

        foreach ($taskIds as $i => $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            Task::where('id', $id)
                ->whereNull('parent_task_id')
                ->update([
                    'project_id' => $projectId,
                    'position' => $i,
                ]);
        }

        unset($this->projects, $this->tasks);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()
            ->where('archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->with(['tags:id,name,slug', 'predecessors:id,state'])
            ->where('state', '!=', 'done')
            ->orderBy('project_id')
            ->orderBy('position')
            ->orderBy('priority')
            ->orderByRaw('due_at IS NULL, due_at')
            ->get();
    }

    /**
     * @return array<string, \Illuminate\Support\Collection<int, array{task: Task, depth: int}>>
     *   Key: "project:{id}" or "unassigned".
     */
    #[Computed]
    public function groupedTree(): array
    {
        $tasks = $this->tasks;
        $byId = $tasks->keyBy('id');
        $childrenOf = [];
        foreach ($tasks as $t) {
            if ($t->parent_task_id !== null && $byId->has($t->parent_task_id)) {
                $childrenOf[$t->parent_task_id][] = $t->id;
            }
        }

        $groups = [];
        foreach ($this->projects as $p) {
            $groups['project:'.$p->id] = collect();
        }
        $groups['unassigned'] = collect();

        $visited = [];
        $push = function (int $id, int $depth, string $groupKey) use (&$push, &$groups, &$visited, $childrenOf, $byId): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $task = $byId->get($id);
            if (! $task) {
                return;
            }
            $groups[$groupKey]->push(['task' => $task, 'depth' => $depth]);
            foreach ($childrenOf[$id] ?? [] as $childId) {
                $push($childId, $depth + 1, $groupKey);
            }
        };

        foreach ($tasks as $t) {
            if ($t->parent_task_id !== null && $byId->has($t->parent_task_id)) {
                continue; // will be visited from its parent
            }
            $key = $t->project_id !== null && isset($groups['project:'.$t->project_id])
                ? 'project:'.$t->project_id
                : 'unassigned';
            $push($t->id, 0, $key);
        }

        return $groups;
    }
};
?>

@php
    $priorityDot = static function (int $p): string {
        return match ($p) {
            1 => 'bg-rose-400',
            2 => 'bg-amber-400',
            3 => 'bg-neutral-400',
            4 => 'bg-neutral-500',
            5 => 'bg-neutral-600',
            default => 'bg-neutral-500',
        };
    };
@endphp

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Tasks tree') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Grouped by project; subtasks nest under their parent.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('calendar.tasks') }}"
               class="rounded-md border border-neutral-800 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-300 hover:border-neutral-600 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Flat list') }}
            </a>
            <x-ui.new-record-button type="task" :label="__('New task')" />
        </div>
    </header>

    @foreach ($this->groupedTree as $groupKey => $rows)
        @php
            $isUnassigned = $groupKey === 'unassigned';
            $project = $isUnassigned ? null : $this->projects->firstWhere('id', (int) str_replace('project:', '', $groupKey));
            // Hide empty projects to keep the page dense; always show
            // Unassigned if it has rows even when zero projects exist.
            if ($rows->isEmpty()) {
                continue;
            }
        @endphp
        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40"
                 aria-labelledby="group-{{ $groupKey }}-h"
                 x-data="taskTreeSortable"
                 data-tt-group-key="{{ $groupKey }}"
                 @dragover="onDragOver($event)"
                 @drop="onDrop($event)"
                 @dragend="onDragEnd()">
            <header class="flex items-baseline justify-between border-b border-neutral-800/60 px-4 py-2">
                <h3 id="group-{{ $groupKey }}-h" class="flex items-center gap-2 text-sm font-medium text-neutral-200">
                    @if ($project && $project->color)
                        <span aria-hidden="true" class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $project->color }}"></span>
                    @endif
                    @if ($project)
                        <button type="button"
                                wire:click="$dispatch('inspector-open', { type: 'project', id: {{ $project->id }} })"
                                class="hover:text-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ $project->name }}
                        </button>
                    @else
                        <span class="text-neutral-400">{{ __('Unassigned') }}</span>
                    @endif
                </h3>
                <span class="text-[11px] text-neutral-500 tabular-nums">{{ __(':n open', ['n' => $rows->count()]) }}</span>
            </header>
            <ul class="divide-y divide-neutral-800/60">
                @foreach ($rows as $node)
                    @php
                        $task = $node['task'];
                        $depth = $node['depth'];
                        $isDone = $task->state === 'done';
                        $isBlocked = ! $isDone && $task->isBlocked();
                        $dueAt = $task->due_at ? CarbonImmutable::parse($task->due_at) : null;
                        $isOverdue = ! $isDone && ! $isBlocked && $dueAt && $dueAt->isPast() && ! $dueAt->isToday();
                        $isDueToday = ! $isDone && ! $isBlocked && $dueAt && $dueAt->isToday();
                        $indentStyle = $depth > 0 ? 'padding-left: '.(1 + $depth * 1.5).'rem' : '';
                        $subjects = $task->subjects();
                    @endphp
                    <li class="group relative flex items-start gap-3 px-4 py-2.5 text-sm transition hover:bg-neutral-800/30"
                        style="{{ $indentStyle }}"
                        @if ($depth === 0)
                            data-tt-task-id="{{ $task->id }}"
                            draggable="true"
                            @dragstart="onDragStart({{ $task->id }}, $event)"
                        @endif>
                        @if ($depth > 0)
                            <span aria-hidden="true"
                                  class="absolute top-0 bottom-0 w-px bg-neutral-800"
                                  style="left: {{ 1 + ($depth - 1) * 1.5 + 0.6 }}rem"></span>
                        @endif
                        <button type="button"
                                wire:click="toggle({{ $task->id }})"
                                aria-label="{{ $isDone ? __('Mark as open') : __('Mark as done') }}"
                                class="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition
                                       {{ $isDone ? 'border-emerald-500/50 bg-emerald-500/20 text-emerald-400' : 'border-neutral-600 text-transparent hover:border-neutral-400' }}
                                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <svg class="h-2.5 w-2.5" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                <path d="M2.5 6.2 5 8.7l4.5-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button type="button"
                                wire:click="$dispatch('inspector-open', { type: 'task', id: {{ $task->id }} })"
                                class="min-w-0 flex-1 cursor-pointer text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="flex items-baseline gap-2">
                                <span aria-hidden="true"
                                      class="mt-0.5 h-2 w-2 shrink-0 rounded-full {{ $priorityDot($task->priority) }}"
                                      title="P{{ $task->priority }}"></span>
                                <span class="truncate {{ $isDone ? 'text-neutral-500 line-through' : ($isBlocked ? 'text-neutral-500' : 'text-neutral-100') }}">{{ $task->title }}</span>
                                @if ($isBlocked)
                                    <span class="shrink-0 rounded border border-neutral-700 bg-neutral-900/60 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400" title="{{ __('Waiting on a predecessor to finish.') }}">
                                        {{ __('blocked') }}
                                    </span>
                                @endif
                                @if ($dueAt)
                                    <span class="shrink-0 text-[11px] tabular-nums {{ $isOverdue ? 'text-rose-400' : ($isDueToday ? 'text-amber-400' : 'text-neutral-500') }}">
                                        {{ Formatting::date($dueAt) }}
                                    </span>
                                @endif
                            </div>
                            @if ($task->tags->isNotEmpty() || $subjects->isNotEmpty())
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 pl-4">
                                    <x-ui.tag-chips :tags="$task->tags" class="flex-wrap" />
                                    @foreach ($subjects as $subject)
                                        @php
                                            $sLabel = $subject->display_name
                                                ?? $subject->name
                                                ?? $subject->title
                                                ?? '—';
                                            $sType = class_basename($subject);
                                        @endphp
                                        <span class="inline-flex items-center rounded border border-neutral-700 bg-neutral-900/60 px-1.5 py-0.5 text-[10px] text-neutral-400">
                                            <span class="mr-1 text-[9px] uppercase tracking-wider text-neutral-500">{{ $sType }}</span>
                                            {{ $sLabel }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </button>
                        @if (! $isDone && $task->state !== 'dropped')
                            <button type="button"
                                    x-data
                                    x-on:click.stop="$dispatch('subentity-edit-open', { type: 'task', id: null, parentId: {{ $task->id }} })"
                                    aria-label="{{ __('Add subtask') }}"
                                    title="{{ __('Add subtask') }}"
                                    class="absolute right-3 top-2.5 rounded-md border border-neutral-800 bg-neutral-900/80 px-1.5 py-0.5 text-[10px] text-neutral-400 opacity-0 transition group-hover:opacity-100 hover:text-neutral-100 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                + {{ __('sub') }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($this->tasks->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No open tasks.') }}
        </div>
    @endif
</div>
