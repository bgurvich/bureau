<?php

use App\Models\TagRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'state')]
    public string $stateFilter = 'active';

    #[Url(as: 'sort')]
    public string $sortBy = 'priority';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['priority', 'pattern_type', 'pattern', 'tag'], true)) {
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
        $query = TagRule::with('tag:id,name,slug');

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

        if ($this->sortBy === 'tag') {
            $query->leftJoin('tags', 'tags.id', '=', 'tag_rules.tag_id')
                ->orderBy('tags.name', $this->sortDir)
                ->select('tag_rules.*');
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
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Tag rules')"
        :description="__('Auto-attach tags to transactions by description. All matching rules fire (additive); never detaches a manual tag.')">
        <button type="button"
                wire:click="$dispatch('inspector-open', { type: 'tag_rule' })"
                class="rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50">
            {{ __('New rule') }}
        </button>
    </x-ui.page-header>

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
                {{ __('No tag rules yet. Click "New rule" to create one.') }}
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.data-table>
                <thead class="border-b border-neutral-800 bg-neutral-900/60">
                    <tr>
                        <x-ui.sortable-header column="priority" :label="__('Priority')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="pattern_type" :label="__('Type')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="pattern" :label="__('Pattern')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="tag" :label="__('Tag')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800">
                    @foreach ($this->ruleList as $rule)
                        <tr wire:key="tag-rule-{{ $rule->id }}"
                            class="cursor-pointer hover:bg-neutral-800/30 {{ $rule->active ? '' : 'opacity-50' }}"
                            wire:click="$dispatch('inspector-open', { type: 'tag_rule', id: {{ $rule->id }} })">
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-500">{{ $rule->priority }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-neutral-500">{{ $rule->pattern_type }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-neutral-200">{{ $rule->pattern }}</td>
                            <td class="px-3 py-2 text-neutral-200">#{{ $rule->tag?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
        </x-ui.data-table>
    @endif
</div>
