<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app', ['title' => 'Time entries'])]
class extends Component
{
    use WithPagination;

    #[Url(as: 'project')]
    public string $projectId = '';

    #[Url(as: 'billable')]
    public string $billableFilter = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subDays(30)->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    public function updatingProjectId(): void
    {
        $this->resetPage();
    }

    public function updatingBillableFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function entries()
    {
        return TimeEntry::query()
            ->with(['project:id,name,color,hourly_rate,hourly_rate_currency,billable', 'task:id,title'])
            ->when($this->projectId !== '', fn ($q) => $q->where('project_id', $this->projectId))
            ->when($this->billableFilter === 'yes', fn ($q) => $q->where('billable', true))
            ->when($this->billableFilter === 'no', fn ($q) => $q->where('billable', false))
            ->when($this->billableFilter === 'unbilled', fn ($q) => $q->where('billable', true)->where('billed', false))
            ->whereDate('activity_date', '>=', $this->from)
            ->whereDate('activity_date', '<=', $this->to)
            ->orderByDesc('activity_date')
            ->orderByDesc('started_at')
            ->paginate(50);
    }

    /** @return array{total_hours:float,billable_hours:float,earnings:float,count:int} */
    #[Computed]
    public function totals(): array
    {
        $q = TimeEntry::query()
            ->with('project:id,billable,hourly_rate,hourly_rate_currency')
            ->when($this->projectId !== '', fn ($q) => $q->where('project_id', $this->projectId))
            ->when($this->billableFilter === 'yes', fn ($q) => $q->where('billable', true))
            ->when($this->billableFilter === 'no', fn ($q) => $q->where('billable', false))
            ->when($this->billableFilter === 'unbilled', fn ($q) => $q->where('billable', true)->where('billed', false))
            ->whereDate('activity_date', '>=', $this->from)
            ->whereDate('activity_date', '<=', $this->to);

        $totalSec = (int) (clone $q)->sum('duration_seconds');
        $billableSec = (int) (clone $q)->where('billable', true)->sum('duration_seconds');

        $earnings = 0.0;
        (clone $q)->where('billable', true)->get(['duration_seconds', 'project_id'])
            ->groupBy('project_id')
            ->each(function ($group, $projectId) use (&$earnings) {
                $project = Project::find($projectId);
                if (! $project || $project->hourly_rate === null) {
                    return;
                }
                $hours = $group->sum('duration_seconds') / 3600;
                $earnings += $hours * (float) $project->hourly_rate;
            });

        return [
            'total_hours' => round($totalSec / 3600, 2),
            'billable_hours' => round($billableSec / 3600, 2),
            'earnings' => round($earnings, 2),
            'count' => (int) (clone $q)->count(),
        ];
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projects(): Collection
    {
        return Project::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Time entries') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Historical time log.') }}</p>
        </div>
        <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Hours') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ number_format($this->totals['total_hours'], 1) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Billable') }}</dt>
                <dd class="mt-0.5 tabular-nums text-emerald-400">{{ number_format($this->totals['billable_hours'], 1) }}</dd>
            </div>
            @if ($this->totals['earnings'] > 0)
                <div>
                    <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Earnings') }}</dt>
                    <dd class="mt-0.5 tabular-nums text-emerald-400">{{ Formatting::money($this->totals['earnings'], $this->currency) }}</dd>
                </div>
            @endif
        </dl>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="te-project" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Project') }}</label>
            <select wire:model.live="projectId" id="te-project"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All projects') }}</option>
                @foreach ($this->projects as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="te-bill" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Billable') }}</label>
            <select wire:model.live="billableFilter" id="te-bill"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::timeEntryBillableFilters() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="te-from" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From') }}</label>
            <input wire:model.live="from" id="te-from" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="te-to" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('To') }}</label>
            <input wire:model.live="to" id="te-to" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </form>

    @if ($this->entries->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No entries in that range.') }}
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
            <ul class="divide-y divide-neutral-800/60">
                @foreach ($this->entries as $e)
                    @php
                        $hours = round($e->duration_seconds / 3600, 2);
                        $projectRate = $e->billable && $e->project && $e->project->hourly_rate !== null
                            ? $hours * (float) $e->project->hourly_rate
                            : null;
                    @endphp
                    <li class="flex items-start gap-3 px-4 py-2 text-sm">
                        <span aria-hidden="true"
                              class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $e->project?->color ? '' : 'bg-neutral-700' }}"
                              style="{{ $e->project?->color ? 'background-color: '.$e->project->color : '' }}"></span>
                        <div class="w-24 shrink-0 text-xs tabular-nums text-neutral-400">
                            {{ Formatting::date($e->activity_date) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="text-neutral-100">{{ $e->project?->name ?? __('No project') }}</span>
                                @if ($e->task)
                                    <span class="truncate text-xs text-neutral-500">· {{ $e->task->title }}</span>
                                @endif
                            </div>
                            @if ($e->description)
                                <div class="text-[11px] text-neutral-500">{{ $e->description }}</div>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-sm tabular-nums {{ $e->billable ? 'text-emerald-400' : 'text-neutral-100' }}">
                                {{ number_format($hours, 2) }}h
                            </div>
                            @if ($projectRate !== null)
                                <div class="text-[10px] tabular-nums text-emerald-400/80">{{ Formatting::money($projectRate, $e->project?->hourly_rate_currency ?? $this->currency) }}</div>
                            @endif
                            @if ($e->billed)
                                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('billed') }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        <div>{{ $this->entries->links() }}</div>
    @endif
</div>
