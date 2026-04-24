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
    /**
     * When true, renders optional Goal + Project searchable-selects
     * above the textarea. The host must include the BulkTaskPickers
     * trait so bulkGoalId / bulkProjectId / createBulkGoal /
     * createBulkProject / bulkGoalOptions / bulkProjectOptions all
     * resolve. Selection applies to every task created in the batch.
     */
    'showPickers' => false,
])

@php
    $elementId = $id ?? 'bulk-tasks-'.uniqid();
    $submitLabel = $submitLabel ?? __('Add tasks');
@endphp

{{-- Shared bulk-task textarea + submit. Hosted by the tasks-index
     panel, the tasks-bell modal, and the mobile capture page.
     Ctrl+Enter submits without requiring a pointer click. --}}
<div class="space-y-3" x-data>
    @if ($showPickers)
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div>
                <label for="{{ $elementId }}-goal" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Goal (optional)') }}</label>
                <x-ui.searchable-select
                    id="{{ $elementId }}-goal"
                    model="bulkGoalId"
                    :options="['' => '— '.__('none').' —'] + $this->bulkGoalOptions"
                    placeholder="{{ __('— none —') }}"
                    allow-create
                    create-method="createBulkGoal" />
            </div>
            <div>
                <label for="{{ $elementId }}-project" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Project (optional)') }}</label>
                <x-ui.searchable-select
                    id="{{ $elementId }}-project"
                    model="bulkProjectId"
                    :options="['' => '— '.__('none').' —'] + $this->bulkProjectOptions"
                    placeholder="{{ __('— none —') }}"
                    allow-create
                    create-method="createBulkProject" />
            </div>
        </div>
    @endif

    <label for="{{ $elementId }}" class="sr-only">{{ __('Tasks') }}</label>
    <textarea id="{{ $elementId }}"
              wire:model="{{ $modelName }}"
              rows="{{ $rows }}"
              autocomplete="off"
              spellcheck="false"
              @if ($autofocus) x-init="$nextTick(() => $el.focus())" @endif
              x-on:keydown.ctrl.enter.prevent="$wire.{{ $submitMethod }}()"
              x-on:keydown.meta.enter.prevent="$wire.{{ $submitMethod }}()"
              placeholder="{{ __("buy milk tomorrow #errands\ncall @alice about taxes #admin P2 by 5/3\nreview PR in 3 days") }}"
              class="block w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    <p class="text-[11px] text-neutral-500">
        {{ __('One task per line. #tag, @contact, P1–P5, by M/D, today/tomorrow/next week/in N days, or a weekday name. Ctrl/⌘+Enter submits.') }}
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
