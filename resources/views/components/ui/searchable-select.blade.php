@props([
    'model',
    'options',
    'placeholder' => null,
    'id' => null,
    'allowCreate' => false,
    'createMethod' => null,
    // Opt-in: when set (e.g. "contact", "category"), the component renders
    // a small pencil next to the trigger that opens the inspector for
    // whichever option is currently selected. Gives every dropdown a
    // consistent "edit the thing I just picked" affordance without each
    // form having to wire it up by hand.
    'editInspectorType' => null,
])

@php
    $elementId = $id ?? 'ss-' . uniqid();
    $placeholder = $placeholder ?? __('Select…');
@endphp

<div
    x-data="searchableSelect({
        model: @js($model),
        options: @js(array_map(fn ($v) => (string) $v, $options)),
        placeholder: @js($placeholder),
        allowCreate: @js((bool) $allowCreate),
        createMethod: @js((string) ($createMethod ?? '')),
    })"
    @keydown.escape.window="if (open) close()"
    @click.outside="close()"
    class="relative"
>
    <div class="flex items-stretch gap-1">
        <button
            type="button"
            x-ref="trigger"
            @click="toggle()"
            @keydown.enter.prevent="toggle()"
            @keydown.space.prevent="toggle()"
            @keydown.arrow-down.prevent="if (!open) toggle(); else move(1)"
            @keydown.arrow-up.prevent="if (!open) toggle(); else move(-1)"
            :aria-expanded="open.toString()"
            aria-haspopup="listbox"
            id="{{ $elementId }}"
            class="flex min-w-0 flex-1 items-center justify-between rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-left text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
        >
            <span x-text="label || placeholder" :class="label ? 'text-neutral-100' : 'text-neutral-500'" class="truncate"></span>
            <svg class="h-3 w-3 shrink-0 text-neutral-500" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
        @if ($editInspectorType)
            {{-- Pencil → opens the inspector for the currently-selected option.
                 Hidden when nothing is selected. Dispatches the same
                 inspector-open event the rest of the app uses. --}}
            <button
                type="button"
                x-show="value !== '' && value !== null"
                x-cloak
                @click.stop="$dispatch('subentity-edit-open', { type: @js($editInspectorType), id: isNaN(parseInt(value, 10)) ? value : parseInt(value, 10) })"
                :aria-label="'{{ __('Edit selected') }}'"
                title="{{ __('Edit selected') }}"
                class="flex shrink-0 items-center justify-center rounded-md border border-neutral-700 bg-neutral-950 px-2 text-neutral-400 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2 12.5V14h1.5l8.373-8.373-1.5-1.5L2 12.5zM13.707 3.793a1 1 0 0 0 0-1.414l-1.086-1.086a1 1 0 0 0-1.414 0l-1.293 1.293 2.5 2.5 1.293-1.293z" fill="currentColor"/>
                </svg>
            </button>
        @endif
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.75ms
        role="listbox"
        class="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-md border border-neutral-700 bg-neutral-900 shadow-xl"
    >
        <div class="border-b border-neutral-800 p-2">
            <input
                x-ref="search"
                x-model="search"
                type="text"
                placeholder="{{ __('Search…') }}"
                autocomplete="off"
                @keydown.arrow-down.prevent="move(1)"
                @keydown.arrow-up.prevent="move(-1)"
                @keydown.enter.prevent="activate()"
                @keydown.tab.prevent="activate()"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none"
            >
        </div>

        <ul class="max-h-56 overflow-y-auto py-1" x-ref="list">
            <template x-for="(row, idx) in filtered" :key="row[0]">
                <li
                    role="option"
                    :aria-selected="row[0] === value"
                    @click="pick(row[0])"
                    @mouseenter="active = idx"
                    :class="active === idx ? 'bg-neutral-800' : ''"
                    class="cursor-pointer px-3 py-1.5 text-sm text-neutral-200"
                >
                    <span x-text="row[1]"></span>
                </li>
            </template>
            <li
                x-show="showCreateOption"
                role="option"
                @click="createInline()"
                @mouseenter="active = filtered.length"
                :class="active === filtered.length ? 'bg-neutral-800' : ''"
                class="cursor-pointer border-t border-neutral-800 px-3 py-1.5 text-sm text-emerald-300"
            >
                <span x-text="'+ ' + placeholderCreateLabel + ' \'' + search.trim() + '\''"></span>
            </li>
            <li x-show="filtered.length === 0 && !showCreateOption" class="px-3 py-2 text-xs text-neutral-500">
                {{ __('No matches.') }}
            </li>
        </ul>
    </div>
</div>
