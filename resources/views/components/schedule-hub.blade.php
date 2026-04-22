<?php

use App\Support\HubTabMemory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Schedule'])]
class extends Component
{
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    public function mount(): void
    {
        $this->tab = HubTabMemory::resolve('schedule', $this->tab, 'calendar');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['calendar', 'tasks', 'meetings', 'checklists'], true)) {
            $this->tab = $tab;
            HubTabMemory::remember('schedule', $tab);
        }
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Schedule')"
        :description="__('Calendar, tasks, meetings, and recurring rituals in one place. Each tab keeps its own filters.')">
    </x-ui.page-header>

    @php
        $tabs = [
            'calendar' => __('Calendar'),
            'tasks' => __('Tasks'),
            'meetings' => __('Meetings'),
            'checklists' => __('Checklists'),
        ];
    @endphp

    <nav class="flex gap-1 border-b border-neutral-800" aria-label="{{ __('Schedule views') }}">
        @foreach ($tabs as $key => $label)
            @php($active = $tab === $key)
            <button type="button"
                    wire:click="setTab('{{ $key }}')"
                    id="schedule-tab-{{ $key }}"
                    aria-controls="schedule-panel-{{ $key }}"
                    @if ($active) aria-current="page" @endif
                    class="-mb-px border-b-2 px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-100 text-neutral-100' : 'border-transparent text-neutral-500 hover:text-neutral-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div role="tabpanel"
         id="schedule-panel-{{ $tab }}"
         aria-labelledby="schedule-tab-{{ $tab }}">
        @switch($tab)
            @case('tasks')
                <livewire:tasks-index :key="'schedule-tasks'" />
                @break
            @case('meetings')
                <livewire:meetings-index :key="'schedule-meetings'" />
                @break
            @case('checklists')
                <livewire:checklists-index :key="'schedule-checklists'" />
                @break
            @default
                <livewire:calendar-index :key="'schedule-calendar'" />
        @endswitch
    </div>
</div>
