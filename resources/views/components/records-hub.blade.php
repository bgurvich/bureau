<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Records'])]
class extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'documents';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['documents', 'media', 'mail', 'post', 'notes', 'tags'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Records')"
        :description="__('Documents, scans, mail, notes, and tags. All the filed and captured material, under one roof.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'documents' => __('Documents'),
            'media' => __('Media'),
            'mail' => __('Mail'),
            'post' => __('Post'),
            'notes' => __('Notes'),
            'tags' => __('Tags'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Record types') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="records-tab-{{ $key }}"
                    aria-controls="records-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="records-panel-{{ $tab }}"
         aria-labelledby="records-tab-{{ $tab }}">
        @switch($tab)
            @case('media')
                <livewire:media-index :key="'records-media'" />
                @break
            @case('mail')
                <livewire:mail-index :key="'records-mail'" />
                @break
            @case('post')
                <livewire:physical-mail-index :key="'records-post'" />
                @break
            @case('notes')
                <livewire:notes-index :key="'records-notes'" />
                @break
            @case('tags')
                <livewire:tags-index :key="'records-tags'" />
                @break
            @default
                <livewire:documents-index :key="'records-documents'" />
        @endswitch
    </div>
</div>
