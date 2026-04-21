<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Rules'])]
class extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'category';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['category', 'tag'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Rules')"
        :description="__('Pattern-driven automation for transactions. Category rules assign a category; tag rules stamp tags. Both run at import time.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'category' => __('Category rules'),
            'tag' => __('Tag rules'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Rule types') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="rules-tab-{{ $key }}"
                    aria-controls="rules-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="rules-panel-{{ $tab }}"
         aria-labelledby="rules-tab-{{ $tab }}">
        @switch($tab)
            @case('tag')
                <livewire:tag-rules-index :key="'rules-tag'" />
                @break
            @default
                <livewire:category-rules-index :key="'rules-category'" />
        @endswitch
    </div>
</div>
