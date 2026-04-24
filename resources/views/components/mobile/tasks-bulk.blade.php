<?php

use App\Support\TaskBulkCreator;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Tasks'])]
class extends Component
{
    public string $text = '';

    /** @var array<int, string> */
    public array $notes = [];

    public function save(): void
    {
        $result = TaskBulkCreator::run($this->text);
        if ($result['created'] === 0) {
            $this->notes = [__('Type at least one task and tap Add.')];

            return;
        }

        $notes = [__('Added :n task(s).', ['n' => $result['created']])];
        if ($result['unmatched_contacts'] !== []) {
            $notes[] = __('Unmatched: :list', ['list' => implode(' ', $result['unmatched_contacts'])]);
        }
        $this->notes = $notes;
        $this->text = '';
    }
};
?>

<div class="flex min-h-[calc(100dvh-8rem)] flex-col space-y-4">
    <header class="pt-2">
        <a href="{{ route('mobile.capture') }}"
           class="inline-flex items-center gap-1 text-xs text-neutral-400 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <span aria-hidden="true">‹</span> {{ __('Back') }}
        </a>
        <h1 class="mt-1 text-lg font-semibold text-neutral-100">{{ __('Bulk tasks') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('One task per line. Tokens: #tag, @contact, P1–P5, mm-dd.') }}
        </p>
    </header>

    <label for="mtasks-input" class="sr-only">{{ __('Tasks') }}</label>
    <textarea id="mtasks-input"
              wire:model="text"
              rows="12"
              inputmode="text"
              autocapitalize="sentences"
              autocomplete="off"
              spellcheck="false"
              placeholder="{{ __("Pick up dry cleaning #errands 05-03\nCall @alice about taxes #admin P2\nBook dentist 06-15") }}"
              class="block flex-1 w-full rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-3 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>

    @foreach ($notes as $note)
        <p role="status" class="text-xs text-neutral-400">{{ $note }}</p>
    @endforeach

    <button type="button"
            wire:click="save"
            class="sticky bottom-20 w-full rounded-2xl border border-emerald-700/50 bg-emerald-900/30 px-4 py-3 text-sm font-medium text-emerald-200 hover:bg-emerald-900/50 active:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        {{ __('Add tasks') }}
    </button>
</div>
