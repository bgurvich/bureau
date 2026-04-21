<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Planning'])]
class extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'budgets';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['budgets', 'savings_goals'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Planning')"
        :description="__('Forward-looking fiscal targets: monthly budgets by category, and savings goals with milestones.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'budgets' => __('Budgets'),
            'savings_goals' => __('Savings goals'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Planning views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="planning-tab-{{ $key }}"
                    aria-controls="planning-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="planning-panel-{{ $tab }}"
         aria-labelledby="planning-tab-{{ $tab }}">
        @switch($tab)
            @case('savings_goals')
                <livewire:savings-goals-index :key="'planning-savings_goals'" />
                @break
            @default
                <livewire:budgets-index :key="'planning-budgets'" />
        @endswitch
    </div>
</div>
