<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Assets'])]
class extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'properties';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['properties', 'vehicles', 'inventory', 'online_accounts', 'in_case_of'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Assets')"
        :description="__('Things you own — real estate, vehicles, and household items worth tracking.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'properties' => __('Properties'),
            'vehicles' => __('Vehicles'),
            'inventory' => __('Inventory'),
            'online_accounts' => __('Online accounts'),
            'in_case_of' => __('In case of'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Asset types') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="assets-tab-{{ $key }}"
                    aria-controls="assets-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="assets-panel-{{ $tab }}"
         aria-labelledby="assets-tab-{{ $tab }}">
        @switch($tab)
            @case('vehicles')
                <livewire:vehicles-index :key="'assets-vehicles'" />
                @break
            @case('inventory')
                <livewire:inventory-index :key="'assets-inventory'" />
                @break
            @case('online_accounts')
                <livewire:online-accounts-index :key="'assets-online_accounts'" />
                @break
            @case('in_case_of')
                <livewire:in-case-of-pack :key="'assets-in_case_of'" />
                @break
            @default
                <livewire:properties-index :key="'assets-properties'" />
        @endswitch
    </div>
</div>
