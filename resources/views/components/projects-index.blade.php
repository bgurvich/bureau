<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Projects'])]
class extends Component
{
    #[Url(as: 'archived')]
    public bool $showArchived = false;

    #[Url(as: 'q')]
    public string $search = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->projects, $this->grandTotal);
    }

    #[Computed]
    public function projects(): Collection
    {
        $projects = Project::query()
            ->with('client:id,display_name')
            ->when(! $this->showArchived, fn ($q) => $q->where('archived', false))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('name', 'like', $term);
            })
            ->orderBy('archived')
            ->orderBy('name')
            ->get();

        $totals = TimeEntry::whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, SUM(duration_seconds) as total, SUM(CASE WHEN billable THEN duration_seconds ELSE 0 END) as billable_seconds')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        return $projects->map(function (Project $p) use ($totals) {
            $row = $totals[$p->id] ?? null;
            $p->setAttribute('total_seconds', (int) ($row->total ?? 0));
            $p->setAttribute('billable_seconds', (int) ($row->billable_seconds ?? 0));

            return $p;
        });
    }

    #[Computed]
    public function grandTotal(): array
    {
        $sum = TimeEntry::query()->sum('duration_seconds');
        $billable = TimeEntry::where('billable', true)->sum('duration_seconds');

        return [
            'hours' => round($sum / 3600, 1),
            'billable_hours' => round($billable / 3600, 1),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Projects') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Timer targets — each entry logs time to one of these.') }}</p>
        </div>
        <x-ui.new-record-button type="project" :label="__('New project')" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Total hours') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ number_format($this->grandTotal['hours'], 1) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Billable') }}</dt>
                <dd class="mt-0.5 tabular-nums text-emerald-400">{{ number_format($this->grandTotal['billable_hours'], 1) }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="p-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="p-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Project name…') }}">
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="showArchived" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Show archived') }}
        </label>
    </form>

    @if ($this->projects->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No projects yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->projects as $p)
                @php
                    $hours = round($p->total_seconds / 3600, 1);
                    $billableHours = round($p->billable_seconds / 3600, 1);
                    $swatchStyle = $p->color ? 'background-color: '.$p->color : '';
                @endphp
                <li class="{{ $p->archived ? 'opacity-50' : '' }}">
                    <button type="button"
                            wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'project', 'id' => $p->id]) }})"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span aria-hidden="true"
                              class="h-2.5 w-2.5 shrink-0 rounded-full {{ $p->color ? '' : 'bg-neutral-700' }}"
                              style="{{ $swatchStyle }}"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-neutral-100">{{ $p->name }}</span>
                                @if ($p->archived)
                                    <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ __('Archived') }}</span>
                                @endif
                                @if ($p->billable)
                                    <span class="shrink-0 rounded bg-emerald-900/30 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-emerald-400">{{ __('Billable') }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($p->client)
                                    <span>{{ $p->client->display_name }}</span>
                                @endif
                                @if ($p->hourly_rate !== null)
                                    <span class="tabular-nums">{{ Formatting::money((float) $p->hourly_rate, $p->hourly_rate_currency ?? (CurrentHousehold::get()?->default_currency ?? 'USD')) }}/h</span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-sm tabular-nums text-neutral-100">{{ number_format($hours, 1) }}h</div>
                            @if ($billableHours > 0 && $billableHours !== $hours)
                                <div class="text-[10px] tabular-nums text-emerald-400">{{ number_format($billableHours, 1) }}h {{ __('billable') }}</div>
                            @endif
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
