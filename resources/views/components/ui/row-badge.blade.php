@props([
    'state' => 'default',
])

@php
    $styles = match ($state) {
        'active' => 'bg-emerald-900/30 text-emerald-300',
        'paused' => 'bg-neutral-800 text-neutral-400',
        'cancelled', 'abandoned' => 'bg-rose-900/30 text-rose-300',
        'achieved', 'done' => 'bg-emerald-900/30 text-emerald-300',
        'warning' => 'bg-amber-900/30 text-amber-300',
        'over' => 'bg-rose-900/30 text-rose-300',
        default => 'bg-neutral-800 text-neutral-400',
    };
@endphp

{{-- Small inline pill indicating state. Used on rows where the primary
     label needs a modifier ("paused", "cancelled", etc.). Pick a state
     from the map above; unknown keys fall back to neutral-muted. --}}
<span {{ $attributes->class('inline-block rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider '.$styles) }}>
    {{ $slot->isNotEmpty() ? $slot : $state }}
</span>
