<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Ledger'])]
class extends Component
{
    /**
     * Active tab. URL-bound so the browser back button, deep links, and
     * refreshes all land on the right pane. The children are
     * Livewire components with their own URL-bound filter/sort state —
     * we only mount the active one so their `#[Url]` params don't all
     * collide in the query string at once.
     */
    #[Url(as: 'tab')]
    public string $tab = 'accounts';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['accounts', 'transactions', 'import'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Ledger')"
        :description="__('Accounts, transactions, and statement imports in one place. Tabs remember their own filters.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'accounts' => __('Accounts'),
            'transactions' => __('Transactions'),
            'import' => __('Import statements'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Ledger sections') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="ledger-tab-{{ $key }}"
                    aria-controls="ledger-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="ledger-panel-{{ $tab }}"
         aria-labelledby="ledger-tab-{{ $tab }}">
        @switch($tab)
            @case('transactions')
                <livewire:transactions-index :key="'ledger-transactions'" />
                @break
            @case('import')
                <livewire:statements-import :key="'ledger-import'" />
                @break
            @default
                <livewire:accounts-index :key="'ledger-accounts'" />
        @endswitch
    </div>
</div>
