<?php

use App\Models\SavingsGoal;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'state')]
    public string $stateFilter = '';   // '' | active | achieved | paused | abandoned

    #[Url(as: 'sort')]
    public string $sortBy = 'progress';   // progress | target | date | name

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['progress', 'target', 'date', 'name'], true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'name' ? 'asc' : 'desc';
        }
    }

    #[Computed]
    public function goals()
    {
        $query = SavingsGoal::with('account:id,name');

        if ($this->stateFilter !== '') {
            $query->where('state', $this->stateFilter);
        } else {
            // Default: hide paused + abandoned, show active + achieved
            $query->whereIn('state', ['active', 'achieved']);
        }

        // Sort by column; "progress" requires computing ratio in PHP so we
        // load + sort in-memory. Everything else is pushed down to SQL.
        if ($this->sortBy === 'progress') {
            $rows = $query->get();
            $asc = $this->sortDir === 'asc';

            return $rows->sortBy(fn ($g) => $g->progressRatio(), SORT_REGULAR, ! $asc)->values();
        }
        $column = match ($this->sortBy) {
            'target' => 'target_amount',
            'date' => 'target_date',
            default => 'name',
        };

        return $query->orderBy($column, $this->sortDir)->get();
    }

    #[On('inspector-saved')]
    public function onInspectorSaved(): void
    {
        unset($this->goals);
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Savings goals')"
        :description="__('Track progress toward a target. Link an account to auto-update progress from its balance.')">
        <button type="button"
                wire:click="$dispatch('inspector-open', { type: 'savings_goal' })"
                class="rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50">
            {{ __('New goal') }}
        </button>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-3 text-xs text-neutral-400" role="toolbar" aria-label="{{ __('Filters') }}">
        <label class="flex items-center gap-2">
            <span class="text-neutral-500">{{ __('State') }}</span>
            <select wire:model.live="stateFilter"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('active + achieved') }}</option>
                <option value="active">{{ __('active') }}</option>
                <option value="achieved">{{ __('achieved') }}</option>
                <option value="paused">{{ __('paused') }}</option>
                <option value="abandoned">{{ __('abandoned') }}</option>
            </select>
        </label>
        <label class="flex items-center gap-2">
            <span class="text-neutral-500">{{ __('Sort by') }}</span>
            <select wire:model.live="sortBy"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="progress">{{ __('progress') }}</option>
                <option value="target">{{ __('target amount') }}</option>
                <option value="date">{{ __('target date') }}</option>
                <option value="name">{{ __('name') }}</option>
            </select>
        </label>
        <button type="button" wire:click="$toggle('sortDir')"
                class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-neutral-300 hover:border-neutral-500 hover:bg-neutral-800"
                aria-label="{{ __('Toggle sort direction') }}">
            {{ $sortDir === 'asc' ? '↑' : '↓' }}
        </button>
    </div>

    @if ($this->goals->isEmpty())
        <x-ui.empty-state>
            @if ($stateFilter !== '')
                {{ __('No goals in that state.') }}
            @else
                {{ __('No savings goals yet.') }}
            @endif
        </x-ui.empty-state>
    @else
        <ul class="space-y-3">
            @foreach ($this->goals as $g)
                @php
                    $ratio = $g->progressRatio();
                    $saved = $g->currentSaved();
                    $width = min(100, (int) round($ratio * 100));
                @endphp
                <li wire:key="goal-{{ $g->id }}"
                    wire:click="$dispatch('inspector-open', { type: 'savings_goal', id: {{ $g->id }} })"
                    class="cursor-pointer rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 transition hover:border-neutral-600 {{ in_array($g->state, ['achieved', 'paused', 'abandoned'], true) ? 'opacity-70' : '' }}">
                    <div class="flex items-baseline justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2 text-sm font-medium text-neutral-100">
                                <span>{{ $g->name }}</span>
                                @if ($g->state !== 'active')
                                    <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $g->state }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-neutral-500">
                                {{ Formatting::money($saved, $g->currency ?? 'USD') }} / {{ Formatting::money((float) $g->target_amount, $g->currency ?? 'USD') }}
                                @if ($g->target_date)
                                    · {{ $g->target_date->diffForHumans() }}
                                @endif
                                @if ($g->account)
                                    · {{ __('linked to :a', ['a' => $g->account->name]) }}
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded bg-neutral-800">
                        <div class="h-full {{ $ratio >= 1.0 ? 'bg-emerald-500' : 'bg-sky-500' }}"
                             style="width: {{ $width }}%"
                             role="progressbar" aria-valuenow="{{ $width }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
