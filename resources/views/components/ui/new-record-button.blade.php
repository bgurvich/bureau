@props([
    'type',
    'label',
    'shortcut' => null,
])

<button type="button"
        wire:click="$dispatch('inspector-open', { type: '{{ $type }}' })"
        {{ $attributes->class(['flex items-center gap-2 rounded-md bg-neutral-100 px-3 py-1.5 text-xs font-medium text-neutral-900 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300']) }}>
    <span>+ {{ $label }}</span>
    @if ($shortcut)
        <kbd class="rounded border border-neutral-400 bg-neutral-200 px-1 py-0.5 font-mono text-[10px] text-neutral-700">{{ $shortcut }}</kbd>
    @endif
</button>
