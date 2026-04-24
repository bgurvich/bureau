@props([
    /** Livewire property bound to the textarea. */
    'modelName',
    /** Livewire method to invoke on submit. */
    'submitMethod',
    /** Notes array to surface above the submit row (pass $this->bulkNotes or $this->notes). */
    'notes' => [],
    /** Textarea rows count. */
    'rows' => 8,
    /** Visible label on the submit button. */
    'submitLabel' => null,
    /** DOM id on the textarea — unique per host so Ctrl+Enter + autofocus wire cleanly. */
    'id' => null,
    /** Whether the component should autofocus the textarea when it renders. */
    'autofocus' => false,
    /** Hosts that render their own bottom-docked submit (mobile page) can hide the inline one. */
    'hideSubmit' => false,
])

@php
    $elementId = $id ?? 'bulk-tasks-'.uniqid();
    $submitLabel = $submitLabel ?? __('Add tasks');
@endphp

{{-- Shared bulk-task textarea + submit. Hosted by the tasks-index
     panel, the tasks-bell modal, and the mobile capture page.
     Ctrl+Enter submits without requiring a pointer click. --}}
<div class="space-y-3" x-data>
    <label for="{{ $elementId }}" class="sr-only">{{ __('Tasks') }}</label>
    <textarea id="{{ $elementId }}"
              wire:model="{{ $modelName }}"
              rows="{{ $rows }}"
              autocomplete="off"
              spellcheck="false"
              @if ($autofocus) x-init="$nextTick(() => $el.focus())" @endif
              x-on:keydown.ctrl.enter.prevent="$wire.{{ $submitMethod }}()"
              x-on:keydown.meta.enter.prevent="$wire.{{ $submitMethod }}()"
              placeholder="{{ __("Pick up dry cleaning #errands 05-03\nCall @alice about taxes #admin P2\nBook dentist 06-15") }}"
              class="block w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    <p class="text-[11px] text-neutral-500">
        {{ __('One task per line. Tokens: #tag, @contact, P1–P5, mm-dd. Ctrl/⌘+Enter submits.') }}
    </p>
    <div class="flex items-center justify-between gap-3">
        <div class="flex flex-wrap items-baseline gap-3">
            @foreach ($notes as $note)
                <span role="status" class="text-xs text-neutral-400">{{ $note }}</span>
            @endforeach
            {{ $slot ?? '' }}
        </div>
        <div class="flex shrink-0 items-center gap-2">
            {{ $trailingActions ?? '' }}
            @unless ($hideSubmit)
                <button type="button"
                        wire:click="{{ $submitMethod }}"
                        class="rounded-md border border-emerald-700/50 bg-emerald-900/30 px-3 py-1.5 text-xs font-medium text-emerald-200 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ $submitLabel }}
                </button>
            @endunless
        </div>
    </div>
</div>
