<?php

use App\Models\BudgetCap;
use App\Support\BudgetAutoSuggester;
use App\Support\BudgetMonitor;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** Set when the user clicks "Suggest from history" to show the preview panel. */
    public bool $showSuggestions = false;

    #[\Livewire\Attributes\Url(as: 'state')]
    public string $stateFilter = '';    // ''|ok|warning|over

    #[\Livewire\Attributes\Url(as: 'sort')]
    public string $sortBy = 'utilization';

    #[\Livewire\Attributes\Url(as: 'dir')]
    public string $sortDir = 'desc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['category', 'cap', 'spent', 'utilization'], true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'category' ? 'asc' : 'desc';
        }
    }

    #[Computed]
    public function statuses()
    {
        $rows = BudgetMonitor::currentMonthStatuses();

        if ($this->stateFilter !== '') {
            $rows = $rows->filter(fn ($s) => $s->state === $this->stateFilter);
        }

        $asc = $this->sortDir === 'asc';

        return $rows->sortBy(function ($s) {
            return match ($this->sortBy) {
                'category' => $s->cap->category?->name ?? '',
                'cap' => (float) $s->cap->monthly_cap,
                'spent' => $s->spent,
                default => $s->ratio,
            };
        }, SORT_REGULAR, ! $asc)->values();
    }

    #[Computed]
    public function suggestions()
    {
        return $this->showSuggestions ? (new BudgetAutoSuggester)->suggestions() : collect();
    }

    /** Re-render after the Inspector saves or deletes a budget cap. */
    #[On('inspector-saved')]
    public function onInspectorSaved(): void
    {
        unset($this->statuses, $this->suggestions);
    }

    public function toggleSuggestions(): void
    {
        $this->showSuggestions = ! $this->showSuggestions;
        unset($this->suggestions);
    }

    /**
     * Commit a single suggestion as a new BudgetCap, rounded up to the
     * nearest $10 so caps stay user-friendly rather than echoing the raw
     * percentile. Won't overwrite an existing cap — the suggestion panel
     * shows existing caps separately.
     */
    public function applySuggestion(int $categoryId): void
    {
        $current = BudgetCap::where('category_id', $categoryId)->first();
        if ($current) {
            return;
        }
        $suggestions = (new BudgetAutoSuggester)->suggestions();
        $row = $suggestions->first(fn ($s) => (int) $s->category->id === $categoryId);
        if (! $row) {
            return;
        }
        $cap = (float) ceil($row->p75 / 10) * 10;
        BudgetCap::forceCreate([
            'category_id' => $categoryId,
            'monthly_cap' => $cap,
            'currency' => CurrentHousehold::get()?->default_currency ?? 'USD',
            'active' => true,
        ]);
        unset($this->statuses, $this->suggestions);
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Budget envelopes')"
        :description="__('Per-category monthly caps. The attention radar flags envelopes ≥ 80% used.')">
        <button type="button" wire:click="toggleSuggestions"
                class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ $showSuggestions ? __('Hide suggestions') : __('Suggest from history') }}
        </button>
        <button type="button"
                wire:click="$dispatch('inspector-open', { type: 'budget_cap' })"
                class="rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('New envelope') }}
        </button>
    </x-ui.page-header>

    @if ($showSuggestions)
        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4" aria-labelledby="sug-h">
            <header class="mb-3">
                <h3 id="sug-h" class="text-sm font-medium text-neutral-100">{{ __('Suggested caps from last 6 months') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('75th-percentile monthly spend per category, rounded up to the nearest 10. "Apply" creates a new envelope — existing envelopes aren\'t overwritten.') }}
                </p>
            </header>
            @if ($this->suggestions->isEmpty())
                <p class="text-xs text-neutral-500">{{ __('Not enough monthly-spend data yet. Each category needs at least 3 months of history.') }}</p>
            @else
                <table class="w-full text-xs">
                    <thead class="text-[10px] uppercase tracking-wider text-neutral-500">
                        <tr>
                            <th class="py-1 text-left font-medium">{{ __('Category') }}</th>
                            <th class="py-1 text-right font-medium">{{ __('Mean') }}</th>
                            <th class="py-1 text-right font-medium">{{ __('P75') }}</th>
                            <th class="py-1 text-right font-medium">{{ __('Samples') }}</th>
                            <th class="py-1 text-right font-medium">{{ __('Existing cap') }}</th>
                            <th class="py-1 text-right font-medium">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-800">
                        @foreach ($this->suggestions as $sug)
                            <tr>
                                <td class="py-1 text-neutral-100">{{ $sug->category->name }}</td>
                                <td class="py-1 text-right font-mono tabular-nums text-neutral-500">{{ Formatting::money($sug->mean, CurrentHousehold::get()?->default_currency ?? 'USD') }}</td>
                                <td class="py-1 text-right font-mono tabular-nums text-neutral-200">{{ Formatting::money($sug->p75, CurrentHousehold::get()?->default_currency ?? 'USD') }}</td>
                                <td class="py-1 text-right font-mono tabular-nums text-neutral-500">{{ $sug->samples }}</td>
                                <td class="py-1 text-right font-mono tabular-nums {{ $sug->existing ? 'text-neutral-400' : 'text-neutral-600' }}">
                                    {{ $sug->existing ? Formatting::money((float) $sug->existing->monthly_cap, $sug->existing->currency ?? 'USD') : '—' }}
                                </td>
                                <td class="py-1 text-right">
                                    @if ($sug->existing)
                                        <span class="text-neutral-600">{{ __('set') }}</span>
                                    @else
                                        <button type="button" wire:click="applySuggestion({{ $sug->category->id }})"
                                                class="rounded border border-sky-800/40 bg-sky-900/20 px-2 py-0.5 text-sky-200 hover:bg-sky-900/40">
                                            {{ __('Apply') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    @if ($this->statuses->isEmpty())
        <x-ui.empty-state>
            {{ __('No envelopes yet. Click "New envelope" to add one.') }}
        </x-ui.empty-state>
    @else
        <div class="flex flex-wrap items-center gap-3 text-xs text-neutral-400" role="toolbar" aria-label="{{ __('Filters') }}">
            <label class="flex items-center gap-2">
                <span class="text-neutral-500">{{ __('Status') }}</span>
                <select wire:model.live="stateFilter"
                        class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <option value="">{{ __('all') }}</option>
                    <option value="ok">{{ __('ok') }}</option>
                    <option value="warning">{{ __('warning') }}</option>
                    <option value="over">{{ __('over') }}</option>
                </select>
            </label>
        </div>

        <x-ui.data-table>
                <thead class="border-b border-neutral-800 bg-neutral-900/60">
                    <tr>
                        <x-ui.sortable-header column="category" :label="__('Category')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="cap" :label="__('Cap')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="spent" :label="__('Spent MTD')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="utilization" :label="__('Utilization')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800">
                    @foreach ($this->statuses as $s)
                        @php
                            $color = match ($s->state) {
                                'over' => 'bg-rose-500',
                                'warning' => 'bg-amber-400',
                                default => 'bg-emerald-500',
                            };
                            $width = min(100, (int) round($s->ratio * 100));
                        @endphp
                        <tr wire:key="cap-{{ $s->cap->id }}" class="cursor-pointer hover:bg-neutral-800/30 {{ $s->cap->active ? '' : 'opacity-50' }}"
                            wire:click="$dispatch('inspector-open', { type: 'budget_cap', id: {{ $s->cap->id }} })">
                            <td class="px-3 py-2 text-neutral-100">{{ $s->cap->category?->name ?? __('—') }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-200">
                                {{ Formatting::money((float) $s->cap->monthly_cap, $s->cap->currency ?? 'USD') }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-200">
                                {{ Formatting::money($s->spent, $s->cap->currency ?? 'USD') }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex h-2 w-full overflow-hidden rounded bg-neutral-800">
                                    <div class="{{ $color }}" style="width: {{ $width }}%"
                                         role="progressbar" aria-valuenow="{{ $width }}" aria-valuemin="0" aria-valuemax="100"
                                         aria-label="{{ __(':pct%', ['pct' => $width]) }}"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
        </x-ui.data-table>
    @endif
</div>
