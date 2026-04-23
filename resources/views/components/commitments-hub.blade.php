<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Money > Commitments hub — Contracts + Insurance. Both are
 * relationship/legal commitments with lifecycle state (active →
 * expiring → ended) and a counterparty; they belonged together
 * behind one tabbed page rather than two sibling nav entries.
 */
new
#[Layout('components.layouts.app', ['title' => 'Commitments'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('commitments', $this->tab, 'contracts');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['contracts', 'insurance'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('commitments', $tab);
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Commitments')"
        :description="__('Contracts and insurance policies — the legal threads running through your money, assets, and health.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'contracts' => __('Contracts'),
            'insurance' => __('Insurance'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Commitments views') }}">
        @foreach ($tabs as $key => $label)
            @php $active = $tab === $key; @endphp
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="commitments-tab-{{ $key }}"
                    aria-controls="commitments-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="commitments-panel-{{ $tab }}"
         aria-labelledby="commitments-tab-{{ $tab }}">
        @switch($tab)
            @case('insurance')
                <livewire:insurance-index :key="'commitments-insurance'" />
                @break
            @default
                <livewire:contracts-index :key="'commitments-contracts'" />
        @endswitch
    </div>
</div>
