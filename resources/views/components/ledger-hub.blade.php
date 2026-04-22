<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Ledger'])]
class extends Component
{
    /**
     * Active tab. URL-bound so deep links + refreshes land on the right
     * pane. When the URL has no `?tab=`, HubTabMemory restores the
     * last-visited tab from user_hub_preferences and falls back to
     * `accounts` for a fresh user.
     */
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('ledger', $this->tab, 'accounts');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['accounts', 'transactions', 'months', 'inbox', 'import', 'reconcile', 'bookkeeper'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('ledger', $tab);
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
            'months' => __('Months'),
            'reconcile' => __('Reconcile'),
            'inbox' => __('Inbox'),
            'import' => __('Import statements'),
            'bookkeeper' => __('Bookkeeper'),
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
            @case('months')
                <livewire:transactions-months :key="'ledger-months'" />
                @break
            @case('reconcile')
                <livewire:reconciliation-workbench :key="'ledger-reconcile'" />
                @break
            @case('inbox')
                <livewire:inbox :key="'ledger-inbox'" />
                @break
            @case('import')
                <livewire:statements-import :key="'ledger-import'" />
                @break
            @case('bookkeeper')
                <livewire:bookkeeper :key="'ledger-bookkeeper'" />
                @break
            @default
                <livewire:accounts-index :key="'ledger-accounts'" />
        @endswitch
    </div>
</div>
