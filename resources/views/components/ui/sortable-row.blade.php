@props([
    /**
     * Stable key for this row. Coerced to string so integer IDs work too.
     * Must match the key used in any key-keyed server state so `wire:model`
     * paths stay valid across reorders.
     */
    'itemKey',

    /**
     * When false (default) the row renders a `⋮⋮` grip icon as the drag
     * source. When true, the whole `<li>` is draggable — use for rows
     * that have no interactive children that would eat drag events (e.g.
     * photo thumbnails, chip lists).
     */
    'noHandle' => false,
])

@php $k = (string) $itemKey; @endphp
{{-- A row inside an <x-ui.sortable-list>. Parent's Alpine data            --}}
{{-- (`checklistItemsSortable`) is inherited via DOM scope, so onDragStart --}}
{{-- and the dragFrom-based opacity resolve to the list's state.           --}}
<li wire:key="sortable-row-{{ $k }}"
    data-item-key="{{ $k }}"
    @if ($noHandle)
        draggable="true"
        x-on:dragstart="onDragStart(@js($k), $event)"
    @endif
    x-bind:class="dragFrom === @js($k) ? 'opacity-40' : ''"
    {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    @unless ($noHandle)
        <span aria-hidden="true"
              title="{{ __('Drag to reorder') }}"
              draggable="true"
              x-on:dragstart="onDragStart(@js($k), $event)"
              class="cursor-grab select-none px-1 text-neutral-600 hover:text-neutral-400 active:cursor-grabbing">⋮⋮</span>
    @endunless
    {{ $slot }}
</li>
