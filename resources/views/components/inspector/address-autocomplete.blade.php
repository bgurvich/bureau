{{-- Address autocomplete input backed by OSM Nominatim (proxied).

     Props:
       id         — DOM id for <input> + label tying
       label      — visible field label
       model      — Livewire property bound to the typed query string
       event      — browser event name dispatched on pick, carrying the
                    structured suggestion (street/city/state/postcode/country)
       hint       — optional helper text below the input

     The form listens via x-on:<event>.window and syncs its own Livewire
     subfields from the detail.suggestion payload. --}}
@props([
    'id' => 'addr-'.uniqid(),
    'label' => __('Address'),
    'model' => '',
    'event' => 'address-picked',
    'hint' => null,
])
<div
    x-data="addressAutocomplete({ url: @js(route('address.autocomplete')), eventName: @js($event), minChars: 3 })"
    x-on:click.outside="close()"
    class="relative"
>
    <label for="{{ $id }}" class="mb-1 block text-xs text-neutral-400">{{ $label }}</label>
    <input
        id="{{ $id }}"
        @if ($model) wire:model="{{ $model }}" @endif
        x-model="query"
        type="text"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded="open"
        autocomplete="off"
        spellcheck="false"
        x-on:input.debounce.300ms="onInput()"
        x-on:keydown.arrow-down.prevent="open = true; move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.enter.prevent="activate()"
        x-on:keydown.escape="close()"
        placeholder="{{ __('Start typing an address…') }}"
        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">

    <div x-show="loading" class="absolute right-3 top-[30px] text-[10px] uppercase tracking-wider text-neutral-500">
        {{ __('searching') }}
    </div>

    <ul x-show="open"
        x-transition.opacity
        role="listbox"
        class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-neutral-800 bg-neutral-950 py-1 text-xs shadow-xl">
        <template x-for="(s, i) in suggestions" :key="i">
            <li>
                <button type="button"
                        x-on:click="pick(i)"
                        x-on:mouseenter="activeIndex = i"
                        x-bind:class="activeIndex === i ? 'bg-neutral-800/70 text-neutral-50' : 'text-neutral-200'"
                        class="block w-full px-3 py-1.5 text-left hover:bg-neutral-800/60 focus-visible:bg-neutral-800/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                    <span class="block truncate" x-text="s.formatted"></span>
                </button>
            </li>
        </template>
    </ul>

    @if ($hint)
        <p class="mt-1 text-[11px] text-neutral-500">{{ $hint }}</p>
    @endif
</div>
