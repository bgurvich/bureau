<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Life > Logs hub — composition over a collection of day-oriented
 * logs the user accumulates: Journal (reflections), Decisions (ADR-style
 * records), Reading/watching (media log), Food (intake).
 *
 * Kept deliberately separate from Goals — goals are forward-looking
 * targets, these are records of what happened. Different shape, different
 * reading.
 *
 * Hub nests each tab's existing Livewire listing as-is. The inner
 * component's #[Layout] directive is ignored under <livewire:...>
 * embeds so no double-wrapped shell.
 */
new
#[Layout('components.layouts.app', ['title' => 'Logs'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('logs', $this->tab, 'journal');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['journal', 'decisions', 'media_log', 'food'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('logs', $tab);
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Logs')"
        :description="__('What happened, day by day. Journal entries, decisions logged, things read or watched, meals.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'journal' => __('Journal'),
            'decisions' => __('Decisions'),
            'media_log' => __('Reading / watching'),
            'food' => __('Food'),
        ];
    @endphp

    <nav class="flex flex-wrap gap-1 border-b border-neutral-800" aria-label="{{ __('Logs views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="logs-tab-{{ $key }}"
                    aria-controls="logs-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="logs-panel-{{ $tab }}"
         aria-labelledby="logs-tab-{{ $tab }}">
        @switch($tab)
            @case('decisions')
                <livewire:decisions-index :key="'logs-decisions'" />
                @break
            @case('media_log')
                <livewire:media-log-index :key="'logs-media-log'" />
                @break
            @case('food')
                <livewire:food-log-index :key="'logs-food'" />
                @break
            @default
                <livewire:journal-index :key="'logs-journal'" />
        @endswitch
    </div>
</div>
