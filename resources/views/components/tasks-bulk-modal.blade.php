<?php

use App\Support\TaskBulkCreator;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Global bulk-task modal, mounted once in the layout and opened by a
 * dispatched `tasks-bulk-open` event (e.g. from the tasks-bell header
 * button). Modal-only — the dedicated /tasks page still has its own
 * inline bulk toggle for longer sessions.
 */
new class extends Component
{
    public bool $open = false;

    public string $text = '';

    /** @var array<int, string> */
    public array $notes = [];

    #[On('tasks-bulk-open')]
    public function show(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->text = '';
        $this->notes = [];
    }

    public function save(): void
    {
        $result = TaskBulkCreator::run($this->text);
        if ($result['created'] === 0) {
            $this->notes = [__('Type at least one task, then tap Add.')];

            return;
        }

        $notes = [__('Added :n task(s).', ['n' => $result['created']])];
        if ($result['unmatched_contacts'] !== []) {
            $notes[] = __('Unmatched contacts: :list', ['list' => implode(', ', $result['unmatched_contacts'])]);
        }
        $this->notes = $notes;
        $this->text = '';
        $this->dispatch('inspector-saved'); // refresh task lists + bell
    }
};
?>

<div x-data="{ open: @entangle('open').live }"
     x-cloak
     x-show="open"
     @keydown.escape.window="$wire.close()"
     role="dialog"
     aria-modal="true"
     aria-labelledby="tbm-title"
     class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8">
    <div x-show="open"
         x-transition.opacity.duration.150ms
         @click="$wire.close()"
         class="fixed inset-0 bg-neutral-950/70"
         aria-hidden="true"></div>

    <div x-show="open"
         x-transition.opacity.duration.150ms
         class="relative z-10 w-full max-w-xl rounded-xl border border-neutral-800 bg-neutral-900 p-5 shadow-2xl">
        <header class="flex items-baseline justify-between">
            <h2 id="tbm-title" class="text-sm font-semibold text-neutral-100">{{ __('Bulk add tasks') }}</h2>
            <button type="button" wire:click="close"
                    aria-label="{{ __('Close') }}"
                    class="text-neutral-500 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">×</button>
        </header>
        <p class="mt-1 text-xs text-neutral-500">{{ __('One task per line. Tokens: #tag, @contact, P1–P5, mm-dd.') }}</p>
        <label for="tbm-text" class="sr-only">{{ __('Tasks') }}</label>
        <textarea id="tbm-text"
                  wire:model="text"
                  rows="8"
                  autofocus
                  spellcheck="false"
                  placeholder="{{ __("Pick up dry cleaning #errands 05-03\nCall @alice about taxes #admin P2\nBook dentist 06-15") }}"
                  class="mt-3 block w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        <div class="mt-4 flex items-center justify-between gap-3">
            <div class="flex flex-wrap items-baseline gap-3">
                @foreach ($notes as $note)
                    <span role="status" class="text-xs text-neutral-400">{{ $note }}</span>
                @endforeach
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" wire:click="close"
                        class="rounded-md border border-neutral-800 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-300 hover:border-neutral-600 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Close') }}
                </button>
                <button type="button" wire:click="save"
                        class="rounded-md border border-emerald-700/50 bg-emerald-900/30 px-3 py-1.5 text-xs font-medium text-emerald-200 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Add tasks') }}
                </button>
            </div>
        </div>
    </div>
</div>
