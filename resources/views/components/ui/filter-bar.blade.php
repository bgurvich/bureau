@props([
    'label' => 'Filters',
])

{{-- Standard filter toolbar: thin horizontal strip, no container bg/border
     (keeps the list the visual focus). Use <label>+<select>/<input>
     children directly; this component only provides the row scaffold
     and consistent spacing. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-3 text-xs text-neutral-400') }}
     role="toolbar"
     aria-label="{{ $label }}">
    {{ $slot }}
</div>
