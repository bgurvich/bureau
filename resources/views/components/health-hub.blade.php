<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Health'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('health', $this->tab, 'appointments');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['appointments', 'prescriptions', 'providers'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('health', $tab);
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Health')"
        :description="__('Upcoming appointments, active prescriptions, and the providers behind them.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'appointments' => __('Appointments'),
            'prescriptions' => __('Prescriptions'),
            'providers' => __('Providers'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Health views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="health-tab-{{ $key }}"
                    aria-controls="health-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="health-panel-{{ $tab }}"
         aria-labelledby="health-tab-{{ $tab }}">
        @switch($tab)
            @case('prescriptions')
                <livewire:prescriptions-index :key="'health-prescriptions'" />
                @break
            @case('providers')
                <livewire:health-providers-index :key="'health-providers'" />
                @break
            @default
                <livewire:appointments-index :key="'health-appointments'" />
        @endswitch
    </div>
</div>
