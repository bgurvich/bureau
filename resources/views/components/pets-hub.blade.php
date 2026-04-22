<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Pets'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('pets', $this->tab, 'pets');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['pets', 'vaccinations', 'checkups'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('pets', $tab);
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Pets')"
        :description="__('Dogs, cats, and other household members with vaccines, checkups, and grooming to track.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'pets' => __('Pets'),
            'vaccinations' => __('Vaccinations'),
            'checkups' => __('Checkups'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Pet views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="pets-tab-{{ $key }}"
                    aria-controls="pets-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="pets-panel-{{ $tab }}"
         aria-labelledby="pets-tab-{{ $tab }}">
        @switch($tab)
            @case('vaccinations')
                <livewire:pet-vaccinations-index :key="'pets-vaccinations'" />
                @break
            @case('checkups')
                <livewire:pet-checkups-index :key="'pets-checkups'" />
                @break
            @default
                <livewire:pets-index :key="'pets-list'" />
        @endswitch
    </div>
</div>
