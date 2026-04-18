@props([
    'model',
    'options',
    'placeholder' => null,
    'id' => null,
    'allowCreate' => false,
    'createMethod' => null,
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
        class="flex w-full items-center justify-between rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-left text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
    >
        <span x-text="label || placeholder" :class="label ? 'text-neutral-100' : 'text-neutral-500'"></span>
        <svg class="h-3 w-3 shrink-0 text-neutral-500" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>

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
