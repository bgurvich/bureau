<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Recurring'])]
class extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'bills';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['bills', 'subscriptions'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Recurring')"
        :description="__('Everything that repeats on a schedule — bills and income on one tab, auto-renewing subscriptions on the other.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'bills' => __('Bills & Income'),
            'subscriptions' => __('Subscriptions'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Recurring kinds') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="recurring-tab-{{ $key }}"
                    aria-controls="recurring-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="recurring-panel-{{ $tab }}"
         aria-labelledby="recurring-tab-{{ $tab }}">
        @switch($tab)
            @case('subscriptions')
                <livewire:subscriptions-index :key="'recurring-subscriptions'" />
                @break
            @default
                <livewire:bills-index :key="'recurring-bills'" />
        @endswitch
    </div>
</div>
