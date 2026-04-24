<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Productivity'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('productivity', $this->tab, 'goals');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['goals', 'projects', 'tasks', 'tree'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('productivity', $tab);
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Productivity')"
        :description="__('Goals point the direction; projects group the work; tasks move things forward.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'goals' => __('Goals'),
            'projects' => __('Projects'),
            'tasks' => __('Tasks'),
            'tree' => __('Tree'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Productivity views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="productivity-tab-{{ $key }}"
                    aria-controls="productivity-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="productivity-panel-{{ $tab }}"
         aria-labelledby="productivity-tab-{{ $tab }}">
        @switch($tab)
            @case('projects')
                <livewire:projects-index :key="'productivity-projects'" />
                @break
            @case('tasks')
                <livewire:tasks-index :key="'productivity-tasks'" />
                @break
            @case('tree')
                <livewire:tasks-tree :key="'productivity-tree'" />
                @break
            @default
                <livewire:goals-index :key="'productivity-goals'" />
        @endswitch
    </div>
</div>
