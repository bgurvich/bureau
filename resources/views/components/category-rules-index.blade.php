<?php

use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Support\CategoryRuleMatcher;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[\Livewire\Attributes\Url(as: 'q')]
    public string $search = '';

    #[\Livewire\Attributes\Url(as: 'type')]
    public string $typeFilter = '';   // '' | contains | regex

    #[\Livewire\Attributes\Url(as: 'state')]
    public string $stateFilter = 'active';   // '' | active | paused

    #[\Livewire\Attributes\Url(as: 'sort')]
    public string $sortBy = 'priority';

    #[\Livewire\Attributes\Url(as: 'dir')]
    public string $sortDir = 'asc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['priority', 'pattern_type', 'pattern', 'category'], true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    #[Computed]
    public function ruleList()
    {
        $query = CategoryRule::with('category:id,name');

        if ($this->search !== '') {
            $query->where('pattern', 'like', '%'.$this->search.'%');
        }
        if ($this->typeFilter !== '') {
            $query->where('pattern_type', $this->typeFilter);
        }
        if ($this->stateFilter === 'active') {
            $query->where('active', true);
        } elseif ($this->stateFilter === 'paused') {
            $query->where('active', false);
        }

        if ($this->sortBy === 'category') {
            $query->leftJoin('categories', 'categories.id', '=', 'category_rules.category_id')
                ->orderBy('categories.name', $this->sortDir)
                ->select('category_rules.*');
        } else {
            $query->orderBy($this->sortBy, $this->sortDir);
        }

        return $query->orderBy('id')->get();
    }

    #[On('inspector-saved')]
    public function onInspectorSaved(): void
    {
        unset($this->ruleList);
    }

    public function applyToHistory(): void
    {
        $matched = 0;
        Transaction::whereNull('category_id')
            ->orderByDesc('occurred_on')
            ->limit(5000)
            ->get()
            ->each(function ($t) use (&$matched) {
                if (CategoryRuleMatcher::attempt($t)) {
                    $matched++;
                }
            });

        session()->flash('applied_count', $matched);
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Category rules')"
        :description="__('Auto-assign categories to transactions by description. Rules are tried in priority order; lower number wins.')">
        <button type="button"
                wire:click="applyToHistory"
                wire:confirm="{{ __('Scan up to 5,000 uncategorized transactions and apply rules? Won\'t override existing categories.') }}"
                class="rounded-md border border-sky-800 bg-sky-900/20 px-3 py-2 text-sm text-sky-200 hover:bg-sky-900/40">
            {{ __('Apply to history') }}
        </button>
        <button type="button"
                wire:click="$dispatch('inspector-open', { type: 'category_rule' })"
                class="rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50">
            {{ __('New rule') }}
        </button>
    </x-ui.page-header>

    @if (session('applied_count') !== null)
        <div role="status" class="rounded-md border border-sky-800/40 bg-sky-900/20 px-3 py-2 text-sm text-sky-200">
            {{ __('Re-categorized :n historical transaction(s).', ['n' => session('applied_count')]) }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3 text-xs text-neutral-400" role="toolbar" aria-label="{{ __('Filters') }}">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="{{ __('Search patterns…') }}"
               class="w-56 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <label class="flex items-center gap-2">
            <span class="text-neutral-500">{{ __('Type') }}</span>
            <select wire:model.live="typeFilter"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('any') }}</option>
                <option value="contains">{{ __('contains') }}</option>
                <option value="regex">{{ __('regex') }}</option>
            </select>
        </label>
        <label class="flex items-center gap-2">
            <span class="text-neutral-500">{{ __('State') }}</span>
            <select wire:model.live="stateFilter"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="active">{{ __('active') }}</option>
                <option value="paused">{{ __('paused') }}</option>
                <option value="">{{ __('all') }}</option>
            </select>
        </label>
    </div>

    @if ($this->ruleList->isEmpty())
        <x-ui.empty-state>
            @if ($search !== '' || $typeFilter !== '' || $stateFilter !== 'active')
                {{ __('No rules match those filters.') }}
            @else
                {{ __('No rules yet. Click "New rule" to create one.') }}
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.data-table>
                <thead class="border-b border-neutral-800 bg-neutral-900/60">
                    <tr>
                        <x-ui.sortable-header column="priority" :label="__('Priority')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="pattern_type" :label="__('Type')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="pattern" :label="__('Pattern')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="category" :label="__('Category')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800">
                    @foreach ($this->ruleList as $rule)
                        <tr wire:key="rule-{{ $rule->id }}"
                            class="cursor-pointer hover:bg-neutral-800/30 {{ $rule->active ? '' : 'opacity-50' }}"
                            wire:click="$dispatch('inspector-open', { type: 'category_rule', id: {{ $rule->id }} })">
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-500">{{ $rule->priority }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-neutral-500">{{ $rule->pattern_type }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-neutral-200">{{ $rule->pattern }}</td>
                            <td class="px-3 py-2 text-neutral-200">{{ $rule->category?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
        </x-ui.data-table>
    @endif
</div>
