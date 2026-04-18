@props([
    'tags' => null,
    'active' => null,
])

@if ($tags && count($tags))
    <div {{ $attributes->class(['flex flex-wrap gap-1']) }}>
        @foreach ($tags as $tag)
            @php
                $isActive = $active !== null && $active === ($tag->slug ?? null);
                $cls = $isActive
                    ? 'bg-emerald-900/40 text-emerald-200'
                    : 'bg-neutral-800 text-neutral-300';
            @endphp
            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] {{ $cls }}">#{{ $tag->name }}</span>
        @endforeach
    </div>
@endif
