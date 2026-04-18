@props([
    'type',
    'id',
    'label' => null,
])

@php
    $payload = json_encode(['type' => $type, 'id' => $id]);
    $aria = $label ? __('Edit :title', ['title' => $label]) : __('Edit record');
@endphp

<li
    tabindex="0"
    role="button"
    aria-label="{{ $aria }}"
    wire:key="ir-{{ $type }}-{{ $id }}"
    wire:click="$dispatch('inspector-open', {{ $payload }})"
    @keydown.enter.prevent="$wire.dispatch('inspector-open', {{ $payload }})"
    {{ $attributes->class(['cursor-pointer transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300']) }}>
    {{ $slot }}
</li>
