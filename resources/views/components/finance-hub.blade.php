<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Finance'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('finance', $this->tab, 'summary');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['summary', 'yoy'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('finance', $tab);
        }
    }
};
?>

<div class="space-y-5">
    @php
        $tabs = [
            'summary' => __('Overview'),
            'yoy' => __('Year over year'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Finance views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="finance-tab-{{ $key }}"
                    aria-controls="finance-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="finance-panel-{{ $tab }}"
         aria-labelledby="finance-tab-{{ $tab }}">
        @switch($tab)
            @case('yoy')
                <livewire:yoy-spending :key="'finance-yoy'" />
                @break
            @default
                <livewire:finance-overview :key="'finance-summary'" />
        @endswitch
    </div>
</div>
