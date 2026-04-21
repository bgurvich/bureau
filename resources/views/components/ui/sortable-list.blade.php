@props([
    /**
     * Livewire method name that receives the new ordering after drop.
     * The method must accept `array<int, string>` of row keys.
     */
    'reorderMethod' => 'reorderItems',
])

{{-- Reusable sortable list shell. Renders the <ul> with the drag-and-drop --}}
{{-- Alpine hookup (checklistItemsSortable in resources/js/app.ts) and     --}}
{{-- delegates row markup to slotted <x-ui.sortable-row> children.         --}}
{{-- Usage:                                                                --}}
{{--   <x-ui.sortable-list reorder-method="reorderItems">                  --}}
{{--     @foreach ($items as $key => $item)                                --}}
{{--       <x-ui.sortable-row :item-key="$key"> ... </x-ui.sortable-row>   --}}
{{--     @endforeach                                                       --}}
{{--   </x-ui.sortable-list>                                               --}}
@php
    // If the caller passed any class, honour theirs verbatim; otherwise
    // fall back to a vertical-stack layout that suits text repeaters.
    $listClass = $attributes->get('class') ?: 'space-y-1.5';
@endphp
<ul x-data="checklistItemsSortable"
    data-reorder-method="{{ $reorderMethod }}"
    x-on:dragover.prevent="onDragOver($event)"
    x-on:drop.prevent="onDrop()"
    x-on:dragend="onDragEnd()"
    class="{{ $listClass }}"
    {{ $attributes->except('class') }}>
    {{ $slot }}
</ul>
