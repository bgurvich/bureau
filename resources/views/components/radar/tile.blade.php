@props([
    'kind',
    'label',
    'count',
    'color' => 'text-neutral-400',
    'href' => null,
])

<li class="group flex items-baseline justify-between gap-2">
    @if ($href)
        <a href="{{ $href }}"
           class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ $label }}
        </a>
    @else
        <span class="text-neutral-300">{{ $label }}</span>
    @endif
    <span class="flex shrink-0 items-center gap-2">
        <span class="tabular-nums {{ $color }}">{{ $count }}</span>
        <span class="opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
            @include('partials.radar.snooze-menu', ['kind' => $kind])
        </span>
    </span>
</li>
